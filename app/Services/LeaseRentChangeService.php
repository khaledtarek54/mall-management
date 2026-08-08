<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use Carbon\CarbonImmutable;
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
    public function __construct(private ChargeScheduleService $schedule) {}

    /**
     * @param  array{base_rent_monthly:float, service_charge_monthly?:float|null, reason?:string|null, effective_from?:string|\DateTimeInterface|null, origin?:string|null}  $data
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

        // The date the new amount takes effect. Defaults to today, which reproduces the old
        // overwrite-now behaviour; the escalation sweep passes the anniversary, and an operator can
        // schedule a change ahead of time.
        $effectiveFrom = isset($data['effective_from']) && $data['effective_from']
            ? CarbonImmutable::parse($data['effective_from'])->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $origin = $data['origin'] ?? Charge::ORIGIN_MANUAL;

        return DB::transaction(function () use ($lease, $newRent, $newService, $hasServiceUpdate, $data, $effectiveFrom, $origin) {
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

            // The rent schedule: CLOSE the row in force and OPEN the next one from the effective
            // date — never overwrite an amount. That is the whole point of the change; see
            // ChargeScheduleService. If no row exists yet (a lease created via the form before
            // charges were seeded), the first one is opened dated to the commencement, as before.
            $this->schedule->setAmount($lease, 'base_rent', $newRent, $effectiveFrom, [
                'name' => 'Base Rent',
                'vat_applicable' => false,
                'vat_rate' => 0,
            ], $origin);

            if ($hasServiceUpdate) {
                $this->schedule->setAmount($lease, 'service_charge', $newService, $effectiveFrom, [
                    'name' => 'Service Charge',
                    'vat_applicable' => true,
                    'vat_rate' => \App\Support\Vat::standardRate(),
                    // Toggling a service charge OFF must not mint a zero row on a lease that never
                    // had one — the pre-schedule createIfZero:false rule, preserved.
                    'skip_if_zero' => true,
                ], $origin);
            }

            // The marketing levy is a percentage of base rent, so it moves WITH the rent and on the
            // SAME effective date — otherwise a past month would bill the historically-correct rent
            // beside a levy computed from today's, which is a worse inconsistency than the one this
            // change set out to fix.
            if ($newRent > 0) {
                app(MarketingLevyService::class)->createLevyCharge($lease->fresh(), $effectiveFrom);
            }

            return $lease->fresh();
        });
    }
}
