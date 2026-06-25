<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PercentageRentCalculationService
{
    /**
     * Calculate the percentage rent owed for a declaration, based on the lease's
     * percentage-rent configuration. Returns 0 when the lease has no percentage-rent terms.
     *
     * - artificial: percentage_rent = max(0, (sales - threshold) * rate%)
     * - natural_breakpoint: percentage_rent = max(0, sales * rate% - base_rent_monthly)
     */
    public function calculate(TenantSalesDeclaration $declaration): float
    {
        $lease = $declaration->lease;

        if (! $lease || ! $lease->has_percentage_rent) {
            return 0.0;
        }

        $rate = (float) $lease->percentage_rent_rate / 100.0;
        $sales = (float) $declaration->declared_sales;

        $type = $lease->percentage_rent_calculation_type ?? 'artificial';

        if ($type === 'natural_breakpoint') {
            $baseRent = (float) $lease->base_rent_monthly;
            $owed = ($sales * $rate) - $baseRent;
        } else {
            $threshold = (float) ($lease->percentage_rent_threshold ?? 0);
            $owed = ($sales - $threshold) * $rate;
        }

        return round(max(0.0, $owed), 2);
    }

    /**
     * Recalculate and persist `calculated_percentage_rent` on the declaration without locking.
     */
    public function recalculate(TenantSalesDeclaration $declaration): TenantSalesDeclaration
    {
        $declaration->calculated_percentage_rent = $this->calculate($declaration);
        $declaration->save();

        return $declaration;
    }

    /**
     * Lock a declaration: recalculate, persist, mark as locked, and create a one-off Charge
     * so the next monthly billing run picks the percentage rent up.
     *
     * Idempotent: locking an already-locked declaration is a no-op.
     */
    public function lock(TenantSalesDeclaration $declaration, User $lockedBy, ?string $auditNotes = null): TenantSalesDeclaration
    {
        if ($declaration->status === 'locked') {
            return $declaration;
        }

        return DB::transaction(function () use ($declaration, $lockedBy, $auditNotes) {
            $owed = $this->calculate($declaration);

            $declaration->update([
                'calculated_percentage_rent' => $owed,
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by_user_id' => $lockedBy->id,
                'audit_notes' => $auditNotes,
            ]);

            if ($owed > 0) {
                $this->createPercentageRentCharge($declaration, $owed);
            }

            // Notify the lease's tenant. Even when owed is zero we tell them
            // their declaration has been locked — the absence of a charge IS
            // useful information (they were under-threshold this period).
            $tenant = $declaration->lease?->tenant;
            if ($tenant) {
                try {
                    $tenant->notifyPortal(
                        new \App\Notifications\SalesDeclarationLockedNotification($declaration->refresh())
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Sales declaration locked notification failed', [
                        'declaration_id' => $declaration->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $declaration->refresh();
        });
    }

    /**
     * Void a previously-locked declaration: flip status to `disputed`,
     * deactivate the percentage_rent Charge so the next monthly billing
     * run skips it, and stamp audit_notes with the reason.
     *
     * Idempotent: voiding a non-locked declaration is a no-op (the action
     * is UI-gated to status=locked, but we belt-and-braces in case a future
     * caller doesn't gate). Audit M12 F-48 / D-36.
     */
    public function voidLocked(TenantSalesDeclaration $declaration, User $voidedBy, string $reason): TenantSalesDeclaration
    {
        if ($declaration->status !== 'locked') {
            return $declaration;
        }

        return DB::transaction(function () use ($declaration, $voidedBy, $reason) {
            // Find and deactivate the percentage_rent Charge created at lock
            // time. Match by period_start so we don't accidentally void a
            // sibling-period charge on the same lease.
            Charge::where('lease_id', $declaration->lease_id)
                ->where('type', 'percentage_rent')
                ->whereDate('start_date', $declaration->period_start)
                ->update(['is_active' => false, 'end_date' => now()]);

            $existing = $declaration->audit_notes ? rtrim($declaration->audit_notes) . "\n\n" : '';
            $stamp = now()->format('Y-m-d');
            $note = "Voided on {$stamp} by {$voidedBy->name}: {$reason}";

            $declaration->update([
                'status' => 'disputed',
                'audit_notes' => $existing . $note,
            ]);

            return $declaration->refresh();
        });
    }

    private function createPercentageRentCharge(TenantSalesDeclaration $declaration, float $amount): Charge
    {
        return Charge::create([
            'lease_id' => $declaration->lease_id,
            'name' => 'Percentage Rent — '.$declaration->periodLabel(),
            'type' => 'percentage_rent',
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_applicable' => false,
            'vat_rate' => 0,
            'start_date' => $declaration->period_start,
            'end_date' => $declaration->period_end,
            'is_active' => true,
        ]);
    }
}
