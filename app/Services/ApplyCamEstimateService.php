<?php

namespace App\Services;

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\LeaseEvent;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Put next year's proposed service-charge estimate onto the leases (story RC-05).
 *
 * **The loop this closes.** Every year the reconciliation discovered the same shortfall, because
 * nothing ever moved the monthly estimate: the true-up was billed, everyone agreed the estimate was
 * too low, and the estimate stayed too low. Yardi proposes next year's figure on reconciliation;
 * this does the same, and — because phase 1 made rent a date-ranged schedule — applying it is not a
 * special mechanism at all. **A re-estimate is just a new schedule row effective next January.**
 *
 * **Proposing and applying are separate acts.** `generateAllocations()` writes
 * `proposed_monthly_estimate` on every allocation so the operator can review the whole mall side by
 * side; nothing bills until somebody accepts. An operator who disagrees simply never applies it,
 * and the proposal stays on the record as what the arithmetic suggested.
 *
 * The row opens on **1 January of the year after the pool's**, through `ChargeScheduleService`, so
 * the months already billed at the old estimate keep their own amount and history stays readable.
 */
class ApplyCamEstimateService
{
    public function __construct(private ChargeScheduleService $schedule) {}

    /**
     * @return array{applied: int, skipped: int, effective_from: CarbonImmutable}
     */
    public function applyForPool(CamExpensePool $pool): array
    {
        if (! in_array($pool->status, ['reconciled', 'closed'], true)) {
            throw new InvalidArgumentException(
                "Pool #{$pool->id} is '{$pool->status}'; reconcile the year before re-estimating from it."
            );
        }

        $effectiveFrom = CarbonImmutable::create((int) $pool->period_year + 1, 1, 1);

        $applied = 0;
        $skipped = 0;

        $pool->allocations()->with('lease')->get()->each(function (CamAllocation $allocation) use ($effectiveFrom, &$applied, &$skipped): void {
            if ($this->apply($allocation, $effectiveFrom)) {
                $applied++;
            } else {
                $skipped++;
            }
        });

        return ['applied' => $applied, 'skipped' => $skipped, 'effective_from' => $effectiveFrom];
    }

    /** Apply ONE allocation's proposal. Returns false when there is nothing to apply. */
    public function apply(CamAllocation $allocation, ?CarbonImmutable $effectiveFrom = null): bool
    {
        $lease = $allocation->lease;
        $proposed = $allocation->proposed_monthly_estimate;

        if ($lease === null || $proposed === null || (float) $proposed <= 0) {
            return false;
        }

        // Already applied — idempotent, so re-running the bulk action cannot open a second row.
        if ($allocation->estimate_applied_at !== null) {
            return false;
        }

        // A lease that has ended does not get next year's estimate. Its charges were closed at
        // termination, and re-opening one would resurrect billing on a dead tenancy.
        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            return false;
        }

        $effectiveFrom ??= CarbonImmutable::create((int) $allocation->pool->period_year + 1, 1, 1);

        return DB::transaction(function () use ($allocation, $lease, $proposed, $effectiveFrom): bool {
            $previous = (float) ($this->schedule->rowInForce($lease, 'service_charge', $effectiveFrom)?->amount ?? 0);

            $this->schedule->setAmount($lease, 'service_charge', (float) $proposed, $effectiveFrom, [
                'name' => 'Service Charge',
                'vat_applicable' => Vat::rateForType('service_charge') > 0,
                'vat_rate' => Vat::rateForType('service_charge'),
                // A lease that never had a service charge does not acquire one from a recovery
                // proposal — the pre-schedule rule, preserved.
                'skip_if_zero' => true,
            ], Charge::ORIGIN_MANUAL);

            // Keep the lease column in step with the schedule, as every other writer does.
            $lease->update(['service_charge_monthly' => (float) $proposed]);

            $allocation->forceFill(['estimate_applied_at' => now()])->save();

            // A rent-affecting change with a date and a reason, so it lands on the lease's history
            // like every other one (LE-01) rather than appearing as an unexplained schedule row.
            app(RecordLeaseEventService::class)->record(
                $lease,
                LeaseEvent::TYPE_RENT_MODIFICATION,
                $effectiveFrom,
                __('admin.cam.estimate_event_reason', [
                    'year' => $allocation->pool->period_year,
                    'amount' => number_format((float) $proposed, 2),
                ]),
                RecordLeaseEventService::scheduleChangePayload('service_charge', $previous, (float) $proposed),
            );

            return true;
        });
    }
}
