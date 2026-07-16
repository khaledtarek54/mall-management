<?php

namespace App\Services;

use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderPart;
use App\Models\User;
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

            $quantity = round((float) $data['quantity'], 3);
            // Frozen at request time. Re-reading the catalog at approval would restate the
            // value a manager is being asked to sign off — and it is the value that decides
            // which manager (FR-CM-11).
            $unitCost = round((float) ($data['unit_cost'] ?? InventoryItem::find($data['inventory_item_id'])?->unit_cost ?? 0), 2);
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
            if ($approver !== null && (int) $locked->requested_by_user_id === (int) $approver->id) {
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
                'moved_by_user_id' => $approver?->id,
                'reference' => $locked->workOrder?->reference,
            ]);

            $locked->update([
                'status' => MaintenanceWorkOrderPart::STATUS_APPROVED,
                'decided_by_user_id' => $approver?->id,
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
                'decided_by_user_id' => $decider?->id,
                'decided_at' => now(),
                'decision_notes' => $reason,
            ]);

            return $locked->refresh();
        });
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
