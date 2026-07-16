<?php

namespace App\Services;

use App\Models\ApprovalRule;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\User;
use App\Support\ApprovalPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The request-to-purchase lifecycle (module 29, FR-PROC-01..05).
 *
 *   Requested → Approved → Ordered → Received
 *
 * Two things here are load-bearing beyond the happy path:
 *
 * **FR-PROC-02 — approval BEFORE order placement.** Expressed as the absence of a
 * `requested → ordered` edge in `PurchaseRequest::TRANSITIONS`, not as a comment. Ordering an
 * unapproved request is the failure this module exists to prevent, so it is unrepresentable.
 *
 * **FR-PROC-04 / FR-WH-02 — receipt updates stock, linked.** `receive()` is the only path that
 * writes stock here, and it stamps `source_type`/`source_id` back to the request. The existing
 * ad-hoc receipt action does not (it passes free text), which is why GRNI has 166,120 EGP of
 * credits and zero debits on the demo books: nothing can find a receipt to clear it against.
 */
class PurchaseRequestService
{
    /** Raising a need is an inventory act; deciding it is a separate, senior one (see approve()). */
    public const REQUEST_PERMISSION = 'procurement.create';

    public const DECIDE_PERMISSION = 'procurement.decide';

    public const RECEIVE_PERMISSION = 'procurement.receive';

    /**
     * FR-PROC-01 — "item(s), quantity, and justification".
     *
     * @param  array{asset_id:int, justification:string, warehouse_id?:int|null, lines:array<int,array>}  $data
     */
    public function request(array $data, ?User $actor = null): PurchaseRequest
    {
        $actor ??= auth()->user();
        $this->assertCan($actor, self::REQUEST_PERMISSION);

        if (empty($data['lines'])) {
            throw new DomainException(__('admin.procurement.errors.no_lines'));
        }

        return DB::transaction(function () use ($data, $actor) {
            $request = PurchaseRequest::create([
                'asset_id' => $data['asset_id'],
                'justification' => $data['justification'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'status' => PurchaseRequest::STATUS_REQUESTED,
                'requested_by_user_id' => $actor->id,
            ]);

            foreach ($data['lines'] as $line) {
                $request->lines()->create([
                    'inventory_item_id' => $line['inventory_item_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'quantity' => $line['quantity'],
                    // `filled()`, not `??`: a blank '' is not an absent value, and `(float) ''` is
                    // 0.0 — which would price the line at nothing and drop the whole request to the
                    // lowest approval tier. The same trap as the spare-part draw.
                    'unit_cost' => filled($line['unit_cost'] ?? null) ? $line['unit_cost'] : 0,
                ]);
            }

            $request->refresh(); // the lines' saved() hook recomputed the total

            // Frozen, exactly as a part draw freezes it: the record must still say who was
            // SUPPOSED to approve this after someone edits the bands.
            $request->update([
                'required_permission' => ApprovalPolicy::permissionFor(
                    ApprovalRule::MODULE_PURCHASE_REQUEST,
                    (float) $request->total_value
                ),
            ]);

            return $request->refresh();
        });
    }

    /**
     * FR-PROC-02 — the approval that must happen before an order can be placed.
     *
     * @throws DomainException
     */
    public function approve(PurchaseRequest $request, ?string $notes = null, ?User $approver = null): PurchaseRequest
    {
        $approver ??= auth()->user();

        return DB::transaction(function () use ($request, $notes, $approver) {
            $locked = $this->lock($request);
            $this->assertCan($approver, self::DECIDE_PERMISSION);
            $this->assertTransition($locked, PurchaseRequest::STATUS_APPROVED);

            // Re-check against the value, not merely the permission: someone who may approve 500
            // must not approve 50,000 by finding the button. Judged on the CURRENT total, not the
            // frozen tier — if lines changed since, the tier that matters is the one for what is
            // actually being approved.
            if (! ApprovalPolicy::canApprove($approver, ApprovalRule::MODULE_PURCHASE_REQUEST, (float) $locked->total_value)) {
                throw new DomainException(__('admin.procurement.errors.approval_tier', [
                    'value' => number_format((float) $locked->total_value, 2),
                ]));
            }

            // Approving your own request is not approval. The control is a second pair of eyes —
            // the same reasoning as a spare-part draw (FR-CM-10).
            if ((int) $locked->requested_by_user_id === (int) $approver->id) {
                throw new DomainException(__('admin.procurement.errors.self_approval'));
            }

            $locked->update([
                'status' => PurchaseRequest::STATUS_APPROVED,
                'decided_by_user_id' => $approver->id,
                'decided_at' => now(),
                'decision_notes' => $notes,
            ]);

            return $locked->refresh();
        });
    }

    /** @throws DomainException */
    public function reject(PurchaseRequest $request, string $reason, ?User $decider = null): PurchaseRequest
    {
        $decider ??= auth()->user();

        return DB::transaction(function () use ($request, $reason, $decider) {
            $locked = $this->lock($request);
            $this->assertCan($decider, self::DECIDE_PERMISSION);
            $this->assertTransition($locked, PurchaseRequest::STATUS_REJECTED);

            // Refusing is as much an act of authority as approving: whoever cannot approve a
            // 50,000 request should not be able to block it either.
            if (! ApprovalPolicy::canApprove($decider, ApprovalRule::MODULE_PURCHASE_REQUEST, (float) $locked->total_value)) {
                throw new DomainException(__('admin.procurement.errors.approval_tier', [
                    'value' => number_format((float) $locked->total_value, 2),
                ]));
            }

            $locked->update([
                'status' => PurchaseRequest::STATUS_REJECTED,
                'decided_by_user_id' => $decider->id,
                'decided_at' => now(),
                'decision_notes' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Place the order (FR-PROC-05's "Ordered"). Only reachable from `approved` — the transition
     * matrix is what enforces FR-PROC-02.
     *
     * @throws DomainException
     */
    public function order(PurchaseRequest $request, ?int $vendorId = null, ?string $orderReference = null, ?User $actor = null): PurchaseRequest
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $vendorId, $orderReference, $actor) {
            $locked = $this->lock($request);
            $this->assertCan($actor, self::DECIDE_PERMISSION);
            $this->assertTransition($locked, PurchaseRequest::STATUS_ORDERED);

            $locked->update([
                'status' => PurchaseRequest::STATUS_ORDERED,
                'vendor_id' => $vendorId ?? $locked->vendor_id,
                'order_reference' => $orderReference,
                'ordered_by_user_id' => $actor->id,
                'ordered_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * FR-PROC-04 — "approved purchases update stock upon receipt", and FR-WH-02 — the movement
     * carries the procurement reference.
     *
     * The source stamp is the whole point. Without it a receipt is an orphan: it credits GRNI and
     * nothing can ever match it to the vendor's bill to clear it. That is not hypothetical — the
     * demo books carry 166,120 EGP of GRNI credits against zero debits, from 12 receipts none of
     * which carry a source.
     *
     * @throws DomainException
     */
    public function receive(PurchaseRequest $request, ?User $actor = null): PurchaseRequest
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $actor) {
            $locked = $this->lock($request);
            $this->assertCan($actor, self::RECEIVE_PERMISSION);
            $this->assertTransition($locked, PurchaseRequest::STATUS_RECEIVED);

            $stockable = $locked->stockableLines()->get();

            // Where do the goods go? Only a question when something stockable is actually arriving:
            // a request for a service has nowhere to land and needs no warehouse.
            if ($stockable->isNotEmpty() && $locked->warehouse_id === null) {
                throw new DomainException(__('admin.procurement.errors.no_warehouse'));
            }

            if ($stockable->isNotEmpty() && (int) $locked->warehouse->asset_id !== (int) $locked->asset_id) {
                // The mall that requested it is the mall that receives it. Guarded here rather than
                // only in the form, because the form is one caller.
                throw new DomainException(__('admin.procurement.errors.warehouse_scope'));
            }

            foreach ($stockable as $line) {
                /** @var PurchaseRequestLine $line */
                if ($line->stock_movement_id !== null) {
                    continue; // already received — never stock the same line twice
                }

                $movement = app(StockMovementService::class)->record([
                    'warehouse_id' => $locked->warehouse_id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'type' => 'receipt',
                    'quantity' => (float) $line->quantity,
                    'unit_cost' => (float) $line->unit_cost,
                    // FR-WH-02. This is the line that makes GRNI clearable.
                    'source_type' => PurchaseRequest::class,
                    'source_id' => $locked->getKey(),
                    'moved_by_user_id' => $actor->id,
                    'reference' => $locked->reference,
                ]);

                $line->update(['stock_movement_id' => $movement->id]);
            }

            $locked->update([
                'status' => PurchaseRequest::STATUS_RECEIVED,
                'received_by_user_id' => $actor->id,
                'received_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /** @throws DomainException */
    public function cancel(PurchaseRequest $request, string $reason, ?User $actor = null): PurchaseRequest
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $reason, $actor) {
            $locked = $this->lock($request);
            $this->assertCan($actor, self::DECIDE_PERMISSION);
            $this->assertTransition($locked, PurchaseRequest::STATUS_CANCELLED);

            $locked->update([
                'status' => PurchaseRequest::STATUS_CANCELLED,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_notes' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    private function lock(PurchaseRequest $request): PurchaseRequest
    {
        return PurchaseRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @throws DomainException */
    private function assertTransition(PurchaseRequest $request, string $next): void
    {
        if (! in_array($next, PurchaseRequest::TRANSITIONS[$request->status] ?? [], true)) {
            throw new DomainException(__('admin.procurement.errors.bad_transition', [
                'from' => __("admin.procurement.statuses.{$request->status}"),
                'to' => __("admin.procurement.statuses.{$next}"),
            ]));
        }
    }

    /** @throws DomainException */
    private function assertCan(?User $actor, string $permission): void
    {
        if ($actor === null || ! $actor->can($permission)) {
            throw new DomainException(__('admin.procurement.errors.denied'));
        }
    }
}
