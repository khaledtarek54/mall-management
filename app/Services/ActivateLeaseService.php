<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Unit;
use App\Support\LeaseActivation;
use App\Support\LeaseEventNarrative;
use App\Support\Translate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Activate a lease — the act that turns an entered lease into an executed one.
 *
 * Client meeting 2026-09-02, points 1 and 2. Until 2026-09-11 activation was a DROPDOWN: anyone
 * holding `leases.edit` picked `active` on the form, nothing asked whether the deposit or the
 * cheques were in, and the status past the first one was a value rather than the outcome of an
 * act (the SW-238 rule, on the register it had not reached). Voyager, MRI and Entrata all put an
 * approval between entering a lease and its going live; Yardi's entering-vs-posting split puts the
 * approval with accounting, which is who the client named.
 *
 * What it does, under a lock: refuses unless the lease is awaiting activation; refuses on the
 * property's money gate ({@see LeaseActivation::shortfall()} — Yardi's residential "no move-in
 * with a balance", a per-property setting whose default is `none`); refuses if any of the lease's
 * shops has been let to somebody else meanwhile — a pending lease does NOT hold its premises
 * (`HasLeaseTermState::HOLDS_PREMISES`), so activation is the moment it starts to, and the
 * double-let guard belongs here exactly as it belongs in `LeaseCreationService`; then writes the
 * executed status the calendar answers (`Lease::executedStatusFor()`), clears the reservation
 * window, and records the event on the timeline.
 *
 * **Lock order: the LEASE, then its UNITS** — the canon `ConvertLeaseToHoldoverService` records
 * (SW-009c): the observer edge (any lease UPDATE X-locks its unit) fixes leases→units, and
 * unit-first was half a deadlock proven on MySQL. Every guard read behind the lock is a LOCKING
 * read (`isActivelyLeasedForUpdate()`, `depositHeldForUpdate()`), because under REPEATABLE READ a
 * plain read answers from the snapshot taken before the wait.
 */
class ActivateLeaseService
{
    public function __construct(private RecordLeaseEventService $events) {}

    public function activate(Lease $lease, ?int $userId = null): Lease
    {
        return DB::transaction(function () use ($lease, $userId): Lease {
            /** @var Lease $locked */
            $locked = Lease::query()->with('unit')->lockForUpdate()->findOrFail($lease->id);

            if (! LeaseActivation::isAwaiting($locked)) {
                throw new DomainException(__('admin.refusals.lease_not_awaiting_activation', [
                    'reference' => $locked->reference,
                    'status' => Translate::orHumanized('admin.statuses.lease.'.$locked->status, $locked->status),
                ]));
            }

            if (($shortfall = LeaseActivation::shortfall($locked, forUpdate: true)) !== null) {
                [$key, $tokens] = LeaseActivation::refusal($shortfall);

                throw new DomainException(__($key, $tokens));
            }

            // EVERY shop, not the master pointer alone: `syncUnits()` attaches the whole set, and a
            // double-booked additional unit is also counted twice in the CAM denominator.
            $unitIds = $locked->units()->pluck('units.id')->push($locked->unit_id)->unique();

            foreach (Unit::query()->whereIn('id', $unitIds)->lockForUpdate()->orderBy('id')->get() as $unit) {
                if ($unit->isActivelyLeasedForUpdate($locked->id)) {
                    throw new DomainException(__('admin.refusals.lease_activation_unit_taken', [
                        'unit' => $unit->code,
                    ]));
                }
            }

            $status = Lease::executedStatusFor($locked->commencement_date);

            $locked->forceFill([
                'status' => $status,
                'reserved_until' => null,
            ])->save();

            $this->events->record(
                $locked,
                LeaseEvent::TYPE_ACTIVATION,
                CarbonImmutable::today(),
                null,
                [
                    LeaseEventNarrative::KEY => 'lease_activated',
                    'status' => $status,
                    'commencement' => $locked->commencement_date?->toDateString(),
                ],
                userId: $userId,
            );

            return $locked->fresh();
        });
    }
}
