<?php

namespace App\Services;

use App\Models\FacilityWorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **A contractor acknowledges a dispatched job** — step 3 of
 * `docs/modules/12b-VENDOR-PORTAL-DESIGN.md`, and the verb the whole portal is worth building for.
 *
 * `acknowledged_at` is what the response SLA is measured to (FR-CM-07: the clock starts at
 * acceptance, so an engineer is not charged for queue time). Until now it was stamped as a SIDE
 * EFFECT of staff moving the job to `in_progress` — which means the response time this system
 * reports has been *the moment a coordinator got round to updating a column*, not the moment the
 * contractor agreed. Letting the contractor stamp it themselves is, in the design's words, "the
 * single biggest change in data quality, and it costs nothing new to build".
 *
 * **The operator keeps the ability to accept on a contractor's behalf**, deliberately — §9 names
 * "contractors who will not log in" as the thing that would make this portal a bad idea, and the
 * mitigation is that the admin path must not be replaced. So this service takes the ACTOR rather
 * than reading the vendor guard, and both panels call the same one. Two ways to accept a job must
 * not mean two code paths.
 *
 * **Idempotent and lock-safe.** Acceptance is a first-writer-wins stamp: two contacts at the same
 * contractor opening the same job and both pressing accept must not move the clock, and the second
 * must not see an error either — they did what they were asked. The re-read happens INSIDE the
 * transaction after the lock, because a value read before the wait answers from a pre-commit
 * snapshot (the MySQL REPEATABLE READ trap CLAUDE.md records).
 */
class AcceptWorkOrderService
{
    public function accept(FacilityWorkOrder $order, Model $actor): FacilityWorkOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            /** @var FacilityWorkOrder $locked */
            $locked = FacilityWorkOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            // A terminal job cannot be accepted — there is nothing left to agree to, and stamping
            // the clock afterwards would rewrite a response time that has already been reported.
            if ($locked->isTerminal()) {
                throw ValidationException::withMessages([
                    'accept' => [__('vendor.jobs.accept_closed')],
                ]);
            }

            // Already accepted: return quietly. The second presser did nothing wrong, and an error
            // here would teach contractors that the button is unreliable.
            if ($locked->acknowledged_at !== null) {
                return $locked;
            }

            $locked->acknowledged_at = now();
            $locked->save();

            // WHO accepted, in the trail rather than in a column — the work order is audited, so the
            // stamp itself is recorded; what the diff cannot say is whether the contractor agreed or
            // a coordinator agreed for them, and that is the whole difference this feature makes to
            // the SLA figure.
            activity('facility_work_order')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('accepted')
                ->log('facility_work_order.accepted');

            return $locked->refresh();
        });
    }
}
