<?php

namespace App\Services;

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Settings\SlaSettings;
use App\Support\SlaResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The state machine for preventive-maintenance work orders (module 26).
 *
 * Module 26 shipped without a service: `start`/`complete`/`cancel` were written inline
 * in the Filament table action, so the only guards were a permission check and
 * "not already terminal". A work order with 0 of 12 checklist items marked closed
 * cleanly — the done/total counts were already in the list query, but as advisory UI
 * rather than an invariant. FR-PPM-07 requires the gate, so the rules live here where
 * every caller (admin panel, and any future API/portal surface) inherits them.
 *
 * Mirrors `TenantRequestService` (module 11): a TRANSITIONS matrix, an
 * InvalidArgumentException for an illegal hop, and per-transition stamping.
 * Business-rule refusals throw DomainException, which the Filament layer catches and
 * surfaces as a danger notification (the house pattern — cf. PayrollService::approve).
 *
 * **Locking.** The work order is the *aggregate root* for itself AND its checklist:
 * every mutation of either goes through `withOrderLock()`. See that method for why the
 * items table is deliberately never locked directly.
 */
class FacilityWorkOrderService
{
    /**
     * Legal transitions. Keys = current status; values = allowed next statuses.
     *
     * `open → done` is deliberately allowed: a short job is genuinely completed in one
     * go, and forcing a Start click first would be friction with no safety benefit.
     * The real invariant guarding closure is the checklist gate below, not the path
     * taken to reach `done`. Terminal states stay immutable, as in module 11.
     */
    public const TRANSITIONS = [
        'open' => ['in_progress', 'done', 'cancelled'],
        'in_progress' => ['done', 'cancelled'],
        'done' => [],
        'cancelled' => [],
    ];

    /**
     * Move a work order to $next, enforcing the matrix and the completion gate.
     *
     * @param  int|null  $actorId  who performed it; defaults to the authenticated user.
     *
     * @throws InvalidArgumentException on an illegal transition
     * @throws DomainException when the checklist is not fully marked
     */
    public function transition(FacilityWorkOrder $order, string $next, ?int $actorId = null): FacilityWorkOrder
    {
        $actorId ??= auth()->id();

        return $this->withOrderLock($order->getKey(), function (FacilityWorkOrder $locked) use ($next, $actorId) {
            $current = $locked->status;

            if (! in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
                throw new InvalidArgumentException("Illegal transition: {$current} → {$next}");
            }

            $payload = ['status' => $next];

            // FR-CM-07 — the RESOLUTION clock runs from ACCEPTANCE, not from creation. Module 11
            // gets this wrong: it stamps target_resolution_at at create-time, so a request
            // nobody picks up for three days has already burned its entire SLA before an
            // engineer sees it, and the breach says more about the queue than the work.
            // Accepting a CM (open → in_progress) is the moment the operator takes it on.
            // Stamped once. Unreachable today — the matrix has no path back into
            // in_progress — but the check is what keeps that true if a state like
            // "awaiting parts" is added later (module 11 already has awaiting_tenant →
            // in_progress). Without it, such a hop would silently reset the deadline and
            // erase the elapsed time, which is how an SLA quietly stops meaning anything.
            //
            // What changed 2026-08-12: the deadline already EXISTS, stamped at creation from the
            // response target (`FacilityWorkOrder::stampSlaClocks()`) — because `open → done` is
            // a legal hop, and a job that never passed through here therefore used to have no
            // deadline at all, escaping the scan, the penalty and every filter permanently.
            // Accepting can only pull the deadline IN, never push it out: an engineer who takes the
            // job on promptly gets their full window from that moment, while accepting late must
            // not buy extra time to finish. Hence min(), not assignment.
            if ($next === 'in_progress' && $locked->isCorrective() && $locked->acknowledged_at === null) {
                $payload['acknowledged_at'] = now();

                // On the clock this job was PROMISED, not on bare hours. Re-deriving in calendar
                // time discarded the working deadline — and because the working one is always later
                // in wall-clock, the `min()` below then picked the calendar figure every time,
                // leaving `sla_clock` saying `working` while the deadline said otherwise.
                $fromAcceptance = FacilityWorkOrder::advance(
                    now(),
                    SlaResolver::hoursFor($locked->asset_id, $locked->priority),
                    $locked->sla_clock ?? FacilityWorkOrder::SLA_CLOCK_CALENDAR,
                    $locked->asset_id,
                );

                $payload['target_resolution_at'] = $locked->target_resolution_at === null
                    ? $fromAcceptance
                    : $fromAcceptance->min($locked->target_resolution_at);
            }

            if ($next === 'done') {
                $this->assertChecklistComplete($locked);
                $this->assertEvidencePresent($locked);

                $payload['completed_at'] = now();
                $payload['completed_by_user_id'] = $actorId;
            }

            $locked->update($payload);
            $locked->refresh();

            // FR-CM-08 — the job stopped running late, so an accruing penalty must stop
            // growing. Assessed here rather than waiting for the next hourly scan, which
            // filters to OPEN orders and would never look at this one again.
            if (in_array($next, FacilityWorkOrder::TERMINAL, true) && $locked->isCorrective()) {
                app(AssessSlaPenaltyService::class)->assess($locked);
            }

            return $locked;
        });
    }

    /**
     * Record an outcome on a checklist item (FR-PPM-07), stamping who/when.
     *
     * Passing `pending` un-marks the item and clears the stamp, so an engineer can
     * correct a mistake while the order is still open.
     *
     * @throws InvalidArgumentException on an unknown result
     * @throws DomainException if the order is terminal
     */
    public function markItem(FacilityWorkOrderItem $item, string $result, ?int $actorId = null): FacilityWorkOrderItem
    {
        if (! in_array($result, FacilityWorkOrderItem::RESULTS, true)) {
            throw new InvalidArgumentException("Unknown checklist result: {$result}");
        }

        $actorId ??= auth()->id();
        $marked = $result !== FacilityWorkOrderItem::RESULT_PENDING;

        return $this->withOrderLock($item->facility_work_order_id, function (FacilityWorkOrder $order) use ($item, $result, $marked, $actorId) {
            $this->assertNotTerminal($order);

            $item->update([
                'result' => $result,
                'marked_at' => $marked ? now() : null,
                'marked_by_user_id' => $marked ? $actorId : null,
            ]);

            return $item->refresh();
        });
    }

    /**
     * Add a check to an open order's checklist.
     *
     * Goes through the lock because a new item is born `pending`: appending one to an
     * order that is mid-complete would otherwise slip past the gate's count.
     *
     * @throws DomainException if the order is terminal
     */
    public function addItem(FacilityWorkOrder $order, string $label): FacilityWorkOrderItem
    {
        return $this->withOrderLock($order->getKey(), function (FacilityWorkOrder $locked) use ($label) {
            $this->assertNotTerminal($locked);

            return $locked->items()->create(['label' => $label]);
        });
    }

    /**
     * Remove a check from an open order's checklist.
     *
     * A deletion can only ever *reduce* the pending count, so it cannot defeat the gate
     * — but a terminal order's checklist is frozen, and that rule belongs here rather
     * than only in the Filament layer.
     *
     * @throws DomainException if the order is terminal
     */
    public function removeItem(FacilityWorkOrderItem $item): void
    {
        $this->withOrderLock($item->facility_work_order_id, function (FacilityWorkOrder $order) use ($item) {
            $this->assertNotTerminal($order);

            $item->delete();
        });
    }

    /**
     * Run $fn holding the work order's row lock, inside a transaction.
     *
     * **Why the parent and not the items.** The completion gate *counts* pending items,
     * and a count cannot lock rows that do not exist yet — so locking the item range
     * would still let `addItem()` insert a fresh `pending` row straight past it. Instead
     * every writer of the order OR its checklist contends for the single
     * `facility_work_orders` row, which makes them strictly serial.
     *
     * This is load-bearing, not defensive: with the gate reading items unlocked and the
     * item writers taking no parent lock, `SELECT ... FOR UPDATE` on the order
     * serialized nothing. Two connections on real MySQL reproduced it — T1 locks the
     * order and sees `pending = 0`; T2 un-marks an item without ever blocking; T1
     * commits `done`. The order closes with an unchecked item, and is then
     * unrecoverable in-app: `done` is terminal, so the checklist freezes with the
     * violation baked in.
     */
    protected function withOrderLock(int $orderId, callable $fn): mixed
    {
        return DB::transaction(function () use ($orderId, $fn) {
            /** @var FacilityWorkOrder $order */
            $order = FacilityWorkOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();

            return $fn($order);
        });
    }

    /** @throws DomainException */
    protected function assertNotTerminal(FacilityWorkOrder $order): void
    {
        if ($order->isTerminal()) {
            throw new DomainException(__('admin.facility.errors.order_terminal'));
        }
    }

    /**
     * FR-PPM-07 — every checklist item must carry an outcome before the visit closes.
     *
     * A *failed* item does not block closure: the visit happened and was recorded, and
     * the fault becomes corrective maintenance (FR-CM-01). Only an item nobody looked
     * at blocks. An order with no checklist at all (ad-hoc, no plan template) is
     * vacuously complete — the gate must not strand jobs that never had items.
     *
     * Callers must already hold the order's lock (see withOrderLock) — the count here
     * is only sound because no item writer can run concurrently with it.
     *
     * @throws DomainException
     */
    /**
     * A finished job must show something for itself — when the operator has asked for that.
     *
     * Guarded in the SERVICE rather than on the model or the form, for the reason this codebase
     * keeps rediscovering: `transition()` is the one road to `done` (the Filament action, the
     * console, and any future API all come through here), while a form guard protects one screen
     * and a model guard would also fire on the data fixes and backfills that legitimately move a
     * historical order.
     *
     * The refusal is a DomainException, so it renders as a toast telling the engineer what to do,
     * not a 500.
     */
    protected function assertEvidencePresent(FacilityWorkOrder $order): void
    {
        if (! app(SlaSettings::class)->require_completion_evidence) {
            return;
        }

        if ($order->hasEvidence()) {
            return;
        }

        throw new DomainException(__('admin.errors.work_order_needs_evidence'));
    }

    protected function assertChecklistComplete(FacilityWorkOrder $order): void
    {
        $pending = $order->items()->pending()->count();

        if ($pending > 0) {
            throw new DomainException(
                __('admin.facility.errors.checklist_incomplete', ['count' => $pending])
            );
        }
    }
}
