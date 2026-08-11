<?php

namespace App\Services;

use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderPart;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\ApprovalPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Spare parts on a work order (FR-CM-09/10/11, FR-INV-04).
 *
 * Internal draws are **requested, not taken**: FR-CM-10 requires manager approval, and
 * FR-CM-11 makes *which* manager depend on the part's value — so stock cannot move until
 * someone with the right authority says so. Only on approval does this call
 * `StockMovementService::record()`, which keeps the stock ledger meaning exactly one thing:
 * a row there is stock that actually moved.
 *
 * External purchases are recorded, not approved: FR-CM-10 scopes approval to parts drawn
 * *from internal inventory*. Buying outside is procurement's problem (FR-PROC-*), and
 * pretending otherwise would gate a purchase that has already happened.
 */
class WorkOrderPartService
{
    /**
     * Deciding a draw is an inventory write — approving one moves stock off the shelf.
     *
     * The ladder answers "*which* manager", never "may this person touch inventory at all":
     * with no bands configured `ApprovalPolicy::canApprove()` returns true for ANY signed-in
     * user (its own docblock says so). Without this gate, deleting the bands turns the
     * approval step from a control into an open door — proven: a read-only viewer approved a
     * 50,000 EGP draw and moved the stock.
     */
    public const DECIDE_PERMISSION = 'inventory.create';

    /**
     * Request a part from stock (FR-CM-09 internal). Nothing is decremented yet.
     *
     * @param  array{inventory_item_id:int, warehouse_id:int, quantity:float, unit_cost?:float|null}  $data
     *
     * @throws DomainException if the order is terminal
     */
    public function requestInternal(MaintenanceWorkOrder $order, array $data, ?int $actorId = null): MaintenanceWorkOrderPart
    {
        $actorId ??= auth()->id();

        return DB::transaction(function () use ($order, $data, $actorId) {
            /** @var MaintenanceWorkOrder $locked */
            $locked = MaintenanceWorkOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $this->assertOrderOpen($locked);
            $this->assertWarehouseServesOrder($locked, (int) $data['warehouse_id']);

            $quantity = round((float) $data['quantity'], 3);
            // FROZEN at request time, and that stays: re-reading at approval would restate the
            // value a manager is being asked to sign off — and it is the value that decides which
            // manager (FR-CM-11).
            //
            // `filled()`, not `??`: a blank '' is not an absent value, and `??` waves it straight
            // through to `(float) '' === 0.0` — pricing the part at zero and dropping it to the
            // lowest tier. Proven before that guard: a 500 EGP draw priced itself at 0.00 and asked
            // for tier_1. The same trap as `meter_readings.cost`.
            //
            // The DEFAULT is the weighted-average cost of the stock actually in that warehouse, not
            // the catalogue price (module 22 close-out, 2026-08-11). The catalogue is what we expect
            // to pay NEXT; it drifts from what this stock cost, and both things decided here were
            // wrong when it did:
            //
            //   1. `value` picks the approval tier, so a catalogue left at last year's figure routes
            //      a large draw to a junior approver — the mechanism whose whole job is to escalate,
            //      quietly not escalating;
            //   2. `approve()` records the stock movement with this frozen cost — an EXPLICIT cost,
            //      so it bypasses the weighted-average fallback in StockMovementService::record()
            //      and re-opens the Inventory drift that fallback exists to close.
            //
            // So the bug was never the freezing. It was WHICH figure gets frozen.
            $unitCost = filled($data['unit_cost'] ?? null)
                ? round((float) $data['unit_cost'], 2)
                : round(app(StockMovementService::class)->weightedAverageCost(
                    InventoryItem::findOrFail($data['inventory_item_id']),
                    Warehouse::findOrFail($data['warehouse_id']),
                ), 2);
            $value = round($quantity * $unitCost, 2);

            return MaintenanceWorkOrderPart::create([
                'maintenance_work_order_id' => $locked->getKey(),
                'source' => MaintenanceWorkOrderPart::SOURCE_INTERNAL,
                'inventory_item_id' => $data['inventory_item_id'],
                'warehouse_id' => $data['warehouse_id'],
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'value' => $value,
                'status' => MaintenanceWorkOrderPart::STATUS_PENDING,
                // Frozen too: the record must still say who was SUPPOSED to sign it off
                // after someone edits the bands.
                'required_permission' => ApprovalPolicy::permissionFor(ApprovalRule::MODULE_INVENTORY_DRAW, $value),
                'requested_by_user_id' => $actorId,
            ]);
        });
    }

    /**
     * Record a part bought outside (FR-CM-09 external, FR-INV-04). No approval, no stock
     * movement — this part was never ours.
     *
     * @param  array{description:string, quantity:float, unit_cost:float, vendor_id?:int|null, reference?:string|null}  $data
     *
     * @throws DomainException if the order is terminal
     */
    public function recordExternal(MaintenanceWorkOrder $order, array $data, ?int $actorId = null): MaintenanceWorkOrderPart
    {
        $actorId ??= auth()->id();

        return DB::transaction(function () use ($order, $data, $actorId) {
            /** @var MaintenanceWorkOrder $locked */
            $locked = MaintenanceWorkOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $this->assertOrderOpen($locked);

            return MaintenanceWorkOrderPart::create([
                'maintenance_work_order_id' => $locked->getKey(),
                'source' => MaintenanceWorkOrderPart::SOURCE_EXTERNAL,
                'description' => $data['description'],
                'vendor_id' => $data['vendor_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'quantity' => round((float) $data['quantity'], 3),
                'unit_cost' => round((float) $data['unit_cost'], 2),
                'status' => MaintenanceWorkOrderPart::STATUS_RECORDED,
                'requested_by_user_id' => $actorId,
            ]);
        });
    }

    /**
     * Approve a draw (FR-CM-10/11) — and only here does the stock actually move.
     *
     * The approver's authority is re-checked against the part's value, not merely against a
     * permission: a user who may approve 500 must not approve 50,000 by finding the button.
     *
     * @throws DomainException if not pending, or the approver lacks the tier
     */
    public function approve(MaintenanceWorkOrderPart $part, ?User $approver = null): MaintenanceWorkOrderPart
    {
        $approver ??= auth()->user();

        return DB::transaction(function () use ($part, $approver) {
            /** @var MaintenanceWorkOrderPart $locked */
            $locked = MaintenanceWorkOrderPart::whereKey($part->getKey())->lockForUpdate()->firstOrFail();

            $this->assertPending($locked);
            $this->assertMayDecide($approver);

            if (! ApprovalPolicy::canApprove($approver, ApprovalRule::MODULE_INVENTORY_DRAW, (float) $locked->value)) {
                throw new DomainException(__('admin.preventive_maintenance.errors.part_approval_tier', [
                    'value' => number_format((float) $locked->value, 2),
                ]));
            }

            // Requesting your own draw and approving it is not approval. The FRD asks for a
            // MANAGER's sign-off — the control is a second pair of eyes, and without this
            // an engineer with tier_1 could self-serve every low-value part.
            if ((int) $locked->requested_by_user_id === (int) $approver->id) {
                throw new DomainException(__('admin.preventive_maintenance.errors.part_self_approval'));
            }

            // Now — and only now — the stock moves. record() re-checks on-hand under its own
            // lock, so an approval racing the last unit still can't drive stock negative.
            $movement = app(StockMovementService::class)->record([
                'warehouse_id' => $locked->warehouse_id,
                'inventory_item_id' => $locked->inventory_item_id,
                'type' => 'consumption',
                'quantity' => (float) $locked->quantity,
                'unit_cost' => (float) $locked->unit_cost,
                'source_type' => MaintenanceWorkOrder::class,
                'source_id' => $locked->maintenance_work_order_id,
                'moved_by_user_id' => $approver->id,
                'reference' => $locked->workOrder?->reference,
            ]);

            $locked->update([
                'status' => MaintenanceWorkOrderPart::STATUS_APPROVED,
                'decided_by_user_id' => $approver->id,
                'decided_at' => now(),
                'stock_movement_id' => $movement->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Refuse a draw. Terminal — a rejected request is a decision, not a draft, so the
     * engineer raises a new one rather than editing away the refusal.
     *
     * @throws DomainException if not pending, or the decider lacks the tier
     */
    public function reject(MaintenanceWorkOrderPart $part, string $reason, ?User $decider = null): MaintenanceWorkOrderPart
    {
        $decider ??= auth()->user();

        return DB::transaction(function () use ($part, $reason, $decider) {
            /** @var MaintenanceWorkOrderPart $locked */
            $locked = MaintenanceWorkOrderPart::whereKey($part->getKey())->lockForUpdate()->firstOrFail();

            $this->assertPending($locked);
            $this->assertMayDecide($decider);

            // Refusing is as much an act of authority as approving: whoever can't approve a
            // 50,000 part shouldn't be able to block it either.
            if (! ApprovalPolicy::canApprove($decider, ApprovalRule::MODULE_INVENTORY_DRAW, (float) $locked->value)) {
                throw new DomainException(__('admin.preventive_maintenance.errors.part_approval_tier', [
                    'value' => number_format((float) $locked->value, 2),
                ]));
            }

            $locked->update([
                'status' => MaintenanceWorkOrderPart::STATUS_REJECTED,
                'decided_by_user_id' => $decider->id,
                'decided_at' => now(),
                'decision_notes' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Remove a recorded external purchase (FR-CM-09) — a typo correction, not a decision.
     *
     * External-only, and deliberately so. An external record is the one path with no approval
     * step to catch a fat-finger: it is typed in and it counts against the job immediately, so
     * a mistyped 99,999 EGP gasket had no way back. An *internal* draw doesn't need this — a
     * pending one is `reject()`ed, and an approved one is undone by voiding its stock movement
     * (which un-counts the part, since `counted()` follows the ledger).
     *
     * Soft-deleted, not erased: what someone typed and withdrew is history worth keeping.
     *
     * @throws DomainException if the part isn't an external record, or the order is terminal
     */
    public function remove(MaintenanceWorkOrderPart $part, string $reason, ?User $actor = null): void
    {
        $actor ??= auth()->user();

        DB::transaction(function () use ($part, $reason, $actor) {
            /** @var MaintenanceWorkOrderPart $locked */
            $locked = MaintenanceWorkOrderPart::whereKey($part->getKey())->lockForUpdate()->firstOrFail();

            $this->assertMayDecide($actor);
            $this->assertOrderOpen($locked->workOrder);

            if ($locked->isInternal()) {
                throw new DomainException(__('admin.preventive_maintenance.errors.part_remove_internal'));
            }

            $locked->update([
                'decision_notes' => $reason,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ]);

            $locked->delete();
        });
    }

    /**
     * You draw from your own mall's shelf (property isolation, on a write).
     *
     * Here rather than in the form: the Filament option list is scoped, and its derived `in:`
     * rule does reject a foreign id — but that only protects the one caller that goes through
     * that form. The rule belongs at the write boundary, where the mobile API and procurement
     * arrive too. Proven before this guard: a job on mall AAA consumed BBB's stock, decrementing
     * BBB's on-hand and posting the cost to BBB's GL dimension. The sibling path already knew
     * this (`StockConsumptionRelationManager::authorizedWarehouse()`) — it just lived in the UI.
     *
     * The order's own property is the reference, not `visibleAssetIds()`: an All-Properties user
     * may see both malls and still must not cross the stock between them.
     *
     * @throws DomainException
     */
    private function assertWarehouseServesOrder(MaintenanceWorkOrder $order, int $warehouseId): void
    {
        $warehouse = Warehouse::find($warehouseId);

        if ($warehouse === null || (int) $warehouse->asset_id !== (int) $order->asset_id) {
            throw new DomainException(__('admin.preventive_maintenance.errors.part_warehouse_scope'));
        }
    }

    /**
     * May this person decide a draw at all, ladder or no ladder? See DECIDE_PERMISSION.
     *
     * @throws DomainException
     */
    private function assertMayDecide(?User $user): void
    {
        if ($user === null || ! $user->can(self::DECIDE_PERMISSION)) {
            throw new DomainException(__('admin.preventive_maintenance.errors.part_decide_denied'));
        }
    }

    /** @throws DomainException */
    private function assertPending(MaintenanceWorkOrderPart $part): void
    {
        if (! $part->isPending()) {
            throw new DomainException(__('admin.preventive_maintenance.errors.part_already_decided'));
        }
    }

    /** @throws DomainException */
    private function assertOrderOpen(MaintenanceWorkOrder $order): void
    {
        if ($order->isTerminal()) {
            throw new DomainException(__('admin.preventive_maintenance.errors.order_terminal'));
        }
    }
}
