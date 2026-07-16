<?php

namespace App\Services;

use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
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
class MaintenanceWorkOrderService
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
    public function transition(MaintenanceWorkOrder $order, string $next, ?int $actorId = null): MaintenanceWorkOrder
    {
        $actorId ??= auth()->id();

        return $this->withOrderLock($order->getKey(), function (MaintenanceWorkOrder $locked) use ($next, $actorId) {
            $current = $locked->status;

            if (! in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
                throw new InvalidArgumentException("Illegal transition: {$current} → {$next}");
            }

            $payload = ['status' => $next];

            // FR-CM-07 — the SLA clock starts on ACCEPTANCE, not on creation. Module 11
            // gets this wrong: it stamps target_resolution_at at create-time, so a request
            // nobody picks up for three days has already burned its entire SLA before an
            // engineer sees it, and the breach says more about the queue than the work.
            // Accepting a CM (open → in_progress) is the moment the operator takes it on.
            // Stamped once. Unreachable today — the matrix has no path back into
            // in_progress — but the check is what keeps that true if a state like
            // "awaiting parts" is added later (module 11 already has awaiting_tenant →
            // in_progress). Without it, such a hop would silently reset the deadline and
            // erase the elapsed time, which is how an SLA quietly stops meaning anything.
            if ($next === 'in_progress' && $locked->isCorrective() && $locked->acknowledged_at === null) {
                $payload['acknowledged_at'] = now();
                $payload['target_resolution_at'] = now()->addHours(
                    SlaResolver::hoursFor($locked->asset_id, $locked->priority)
                );
            }

            if ($next === 'done') {
                $this->assertChecklistComplete($locked);

                $payload['completed_at'] = now();
                $payload['completed_by_user_id'] = $actorId;
            }

            $locked->update($payload);

            return $locked->refresh();
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
    public function markItem(MaintenanceWorkOrderItem $item, string $result, ?int $actorId = null): MaintenanceWorkOrderItem
    {
        if (! in_array($result, MaintenanceWorkOrderItem::RESULTS, true)) {
            throw new InvalidArgumentException("Unknown checklist result: {$result}");
        }

        $actorId ??= auth()->id();
        $marked = $result !== MaintenanceWorkOrderItem::RESULT_PENDING;

        return $this->withOrderLock($item->maintenance_work_order_id, function (MaintenanceWorkOrder $order) use ($item, $result, $marked, $actorId) {
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
    public function addItem(MaintenanceWorkOrder $order, string $label): MaintenanceWorkOrderItem
    {
        return $this->withOrderLock($order->getKey(), function (MaintenanceWorkOrder $locked) use ($label) {
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
    public function removeItem(MaintenanceWorkOrderItem $item): void
    {
        $this->withOrderLock($item->maintenance_work_order_id, function (MaintenanceWorkOrder $order) use ($item) {
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
     * `maintenance_work_orders` row, which makes them strictly serial.
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
            /** @var MaintenanceWorkOrder $order */
            $order = MaintenanceWorkOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();

            return $fn($order);
        });
    }

    /** @throws DomainException */
    protected function assertNotTerminal(MaintenanceWorkOrder $order): void
    {
        if ($order->isTerminal()) {
            throw new DomainException(__('admin.preventive_maintenance.errors.order_terminal'));
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
    protected function assertChecklistComplete(MaintenanceWorkOrder $order): void
    {
        $pending = $order->items()->pending()->count();

        if ($pending > 0) {
            throw new DomainException(
                __('admin.preventive_maintenance.errors.checklist_incomplete', ['count' => $pending])
            );
        }
    }
}
