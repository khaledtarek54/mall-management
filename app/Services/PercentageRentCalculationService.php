<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
            // Lock the row + re-check status INSIDE the txn so two concurrent locks (a
            // double-clicked / retried Filament action, or two staff) can't BOTH bill the
            // overage. Under MySQL REPEATABLE READ a non-locking read sees a stale
            // pre-commit snapshot, so reverseOverage() wouldn't see the racing txn's anchor
            // charge and both would reach billOverageImmediately() → two issued invoices +
            // two GL postings for one declaration. Mirrors VoidInvoiceService /
            // CamReconciliationService (the codebase's established lock-safe pattern).
            $declaration = TenantSalesDeclaration::query()->lockForUpdate()->find($declaration->id);
            if (! $declaration || $declaration->status === 'locked') {
                return $declaration; // a racing request already locked it — nothing to do
            }

            $owed = $this->calculate($declaration);

            $declaration->update([
                'calculated_percentage_rent' => $owed,
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by_user_id' => $lockedBy->id,
                'audit_notes' => $auditNotes,
            ]);

            // Re-lock safety: reverse any prior overage for this lease+period (deactivate its
            // anchor charge + void its immediate invoice) before billing the fresh one, so a
            // re-lock can never double-bill.
            $this->reverseOverage($declaration);

            if ($owed > 0) {
                $this->billOverageImmediately($declaration, $owed);
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
     * Void a previously-locked declaration: flip status to `disputed`, reverse the overage
     * (deactivate the anchor Charge AND cancel its immediate invoice, so the GL entry is
     * voided by the sweep), and stamp audit_notes with the reason. A PAID overage invoice
     * can't be voided — VoidInvoiceService throws, the txn rolls back, the void is refused.
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
            // Lock the row + re-check inside the txn (same rationale as lock()): two racing
            // voids must not both run reverseOverage() and double-cancel / double-refund.
            $declaration = TenantSalesDeclaration::query()->lockForUpdate()->find($declaration->id);
            if (! $declaration || $declaration->status !== 'locked') {
                return $declaration; // already voided by a racing request — nothing to do
            }

            // Reverse the overage: deactivate the anchor Charge AND void its immediate
            // invoice (the overage was already billed at lock time). If that invoice has
            // been PAID, VoidInvoiceService throws — the void is refused until it's refunded.
            $this->reverseOverage($declaration);

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

    /**
     * Bill the percentage-rent overage IMMEDIATELY as its own issued invoice. The monthly
     * billing run can't reach a one_time charge dated to a past sales month, so — mirroring the
     * CAM positive true-up (billChargeImmediately) — we invoice it now. The Charge is kept as an
     * INACTIVE traceability anchor + the void/re-lock identity key (matched on
     * start_date = period_start); the money lives on the invoice, posting to the GL as
     * percentage_rent_revenue via the invoice item's `percentage_rent` type.
     */
    private function billOverageImmediately(TenantSalesDeclaration $declaration, float $amount): Charge
    {
        /** @var Lease $lease */
        $lease = $declaration->lease;
        $now = now();
        $label = 'Percentage Rent — '.$declaration->periodLabel();

        // Anchor: is_active=false so the monthly engine (which loads only active charges)
        // never re-bills it; dated to the sales period so void/re-lock match it.
        $charge = Charge::create([
            'lease_id' => $lease->id,
            'name' => $label,
            'type' => 'percentage_rent',
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'one_time',
            'vat_applicable' => false,
            'vat_rate' => 0,
            'start_date' => $declaration->period_start,
            'end_date' => $declaration->period_end,
            'is_active' => false,
        ]);

        // Invoice period = the SALES period (the truthful period the overage covers), NOT
        // now(). That single-month span DOES fall inside MonthlyBillingService's "already
        // billed" window, so both of its idempotency probes explicitly exclude pure
        // percentage-rent overage invoices (whereDoesntHave items type=percentage_rent) —
        // otherwise a back-filled monthly run for this month would skip the base rent.
        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'issue_date' => $now,
            'due_date' => $now->copy()->addDays($lease->payment_terms_days ?? 7),
            'period_start' => $declaration->period_start,
            'period_end' => $declaration->period_end,
            'subtotal' => $amount,
            'vat_amount' => 0,
            'total' => $amount,
            'paid_amount' => 0,
            'balance' => $amount,
            'currency' => $lease->currency ?? 'EGP',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'charge_id' => $charge->id,
            'description' => $label,
            'type' => 'percentage_rent', // → percentage_rent_revenue in the GL journalizer
            'amount' => $amount,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => $amount,
        ]);

        return $charge;
    }

    /**
     * Reverse a period's overage (used by void AND re-lock): deactivate the anchor Charge(s)
     * and void their immediate invoice. Matched by (lease, type, start_date = period_start) so
     * a sibling period is never touched. A PAID overage invoice can't be voided —
     * VoidInvoiceService throws, the caller's transaction rolls back, and the void/re-lock is
     * refused (the invoice must be refunded first).
     */
    private function reverseOverage(TenantSalesDeclaration $declaration): void
    {
        $charges = Charge::where('lease_id', $declaration->lease_id)
            ->where('type', 'percentage_rent')
            ->whereDate('start_date', $declaration->period_start)
            ->get();

        foreach ($charges as $charge) {
            $charge->update(['is_active' => false, 'end_date' => now()]);

            $invoice = InvoiceItem::where('charge_id', $charge->id)->latest('id')->first()?->invoice;
            if ($invoice && ! in_array($invoice->status, ['cancelled', 'credited'], true)) {
                app(VoidInvoiceService::class)->void($invoice, 'Percentage-rent declaration voided / re-locked');
            }
        }
    }
}
