<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Update a lease's contractual rent (and optionally service charge) in a
 * single atomic operation. Keeps Lease.base_rent_monthly /
 * service_charge_monthly aligned with the matching Charge.amount rows so
 * MonthlyBillingService and the dashboard widgets never read drifted
 * numbers. Closes audit M04 F-20 / D-13.
 *
 * Why this isn't on the standard edit form: edits via the form would
 * silently leave Charge.amount at the old value, so the next monthly
 * billing run would bill the old amount even though tables + widgets
 * show the new value.
 */
class LeaseRentChangeService
{
    /**
     * @param  array{base_rent_monthly:float, service_charge_monthly?:float|null, reason?:string|null}  $data
     */
    public function apply(Lease $lease, array $data): Lease
    {
        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            throw new InvalidArgumentException(
                "Lease #{$lease->id} is '{$lease->status}'; only active or pending leases can have their rent changed."
            );
        }

        $newRent = round((float) $data['base_rent_monthly'], 2);
        if ($newRent < 0) {
            throw new InvalidArgumentException('base_rent_monthly must be ≥ 0.');
        }

        $hasServiceUpdate = array_key_exists('service_charge_monthly', $data) && $data['service_charge_monthly'] !== null;
        $newService = $hasServiceUpdate ? round((float) $data['service_charge_monthly'], 2) : null;
        if ($hasServiceUpdate && $newService < 0) {
            throw new InvalidArgumentException('service_charge_monthly must be ≥ 0.');
        }

        return DB::transaction(function () use ($lease, $newRent, $newService, $hasServiceUpdate, $data) {
            $existingNotes = $lease->notes ? rtrim($lease->notes) . "\n\n" : '';
            $stamp = now()->format('Y-m-d');
            $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
            $line = $reason !== ''
                ? "Rent updated on {$stamp}: {$reason}"
                : "Rent updated on {$stamp}.";

            $updates = [
                'base_rent_monthly' => $newRent,
                'notes' => $existingNotes . $line,
            ];
            if ($hasServiceUpdate) {
                $updates['service_charge_monthly'] = $newService;
            }
            $lease->update($updates);

            // Sync the most-recent active base_rent Charge. If none exists
            // yet (e.g. lease created via form before charges were seeded
            // and LeaseObserver hadn't fired), we create one now.
            $this->syncCharge(
                $lease,
                type: 'base_rent',
                amount: $newRent,
                name: 'Base Rent',
                vatApplicable: false,
                vatRate: 0,
            );

            if ($hasServiceUpdate) {
                $this->syncCharge(
                    $lease,
                    type: 'service_charge',
                    amount: $newService,
                    name: 'Service Charge',
                    vatApplicable: true,
                    vatRate: 14.00,
                    createIfZero: false,
                );
            }

            // The marketing levy is 5% of base rent — keep it in lock-step so the
            // next bill (and the marketing fund) reflects the new rent.
            if ($newRent > 0) {
                app(\App\Services\MarketingLevyService::class)->createLevyCharge($lease->fresh());
            }

            return $lease->fresh();
        });
    }

    /**
     * Idempotent: updates the most-recent active row matching the type, or
     * creates one if none exists (unless $createIfZero is false and the
     * amount is 0 — used by service-charge to allow toggling off).
     */
    private function syncCharge(
        Lease $lease,
        string $type,
        float $amount,
        string $name,
        bool $vatApplicable,
        float $vatRate,
        bool $createIfZero = true,
    ): void {
        $existing = Charge::where('lease_id', $lease->id)
            ->where('type', $type)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->update(['amount' => $amount]);

            return;
        }

        if ($amount <= 0 && ! $createIfZero) {
            return;
        }

        Charge::create([
            'lease_id' => $lease->id,
            'name' => $name,
            'type' => $type,
            'amount' => $amount,
            'currency' => $lease->currency ?? 'EGP',
            'frequency' => 'monthly',
            'vat_applicable' => $vatApplicable,
            'vat_rate' => $vatRate,
            // Date the (edge-case) newly-created charge to the lease commencement —
            // consistent with LeaseCreationService/LeaseRenewalService, so a missing
            // charge recreated here bills the lease's term correctly rather than only
            // from the current month.
            'start_date' => $lease->commencement_date,
            'is_active' => true,
        ]);
    }
}
