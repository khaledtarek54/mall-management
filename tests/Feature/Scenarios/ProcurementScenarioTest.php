<?php

use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\PurchaseRequestService;
use App\Support\ApprovalPolicy;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * Procurement — FR-PROC-01..05 + FR-WH-02.
 *
 * The two that matter beyond the happy path: **approval before order placement** (FR-PROC-02) and
 * **a receipt that carries its procurement reference** (FR-WH-02) — the missing link that leaves
 * GRNI uncleaable.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    $this->svc = app(PurchaseRequestService::class);
    $this->asset = makeAsset(['code' => 'PRC']);
    $this->warehouse = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Main', 'code' => 'W1']);
    $this->item = InventoryItem::create(['sku' => 'FILT', 'name' => 'Air filter', 'unit' => 'each', 'unit_cost' => 50]);
    $this->buyer = makeUser('operations', [$this->asset->id]);
    $this->manager = makeUser('manager', [$this->asset->id]);
});

function pr(array $overrides = [], array $lines = []): PurchaseRequest
{
    return test()->svc->request(array_merge([
        'asset_id' => test()->asset->id,
        'justification' => 'The spares ran out and two units are down.',
        'warehouse_id' => test()->warehouse->id,
        'lines' => $lines ?: [['inventory_item_id' => test()->item->id, 'quantity' => 10, 'unit_cost' => 50]], // 500
    ], $overrides), test()->buyer);
}

function onHandPrc(): float
{
    return (float) StockMovement::where('inventory_item_id', test()->item->id)->sum('quantity');
}

/* ---- FR-PROC-01: the request ------------------------------------------- */

it('raises a request with items, quantity and justification', function () {
    $r = pr();

    expect($r->status)->toBe(PurchaseRequest::STATUS_REQUESTED);
    expect($r->reference)->toStartWith('PR-PRC-');
    expect((float) $r->total_value)->toBe(500.0);
    expect($r->lines)->toHaveCount(1);
    expect($r->requested_by_user_id)->toBe($this->buyer->id);
});

it('refuses a request with no items', function () {
    expect(fn () => pr(['lines' => []]))->toThrow(DomainException::class);
});

it('derives the total from the lines and keeps it in step', function () {
    // The total is what an approver signs off — it can never lag the lines.
    $r = pr([], [
        ['inventory_item_id' => $this->item->id, 'quantity' => 10, 'unit_cost' => 50],
        ['description' => 'Callout fee', 'quantity' => 1, 'unit_cost' => 300],
    ]);
    expect((float) $r->total_value)->toBe(800.0);

    $r->lines()->whereNotNull('description')->first()->delete();
    expect((float) $r->fresh()->total_value)->toBe(500.0);
});

it('prices a blank unit cost at zero rather than crashing, and tiers on the real total', function () {
    // `?? ` would let (float) '' === 0.0 through silently; filled() is explicit about it.
    $r = pr([], [['inventory_item_id' => $this->item->id, 'quantity' => 400, 'unit_cost' => 50]]); // 20,000
    expect($r->required_permission)->toBe('approvals.tier_3');
});

/* ---- FR-PROC-02: approval BEFORE order placement ------------------------ */

it('cannot order a request that was never approved', function () {
    // This is FR-PROC-02, and it is enforced by the absence of a requested->ordered edge.
    $r = pr();

    expect(fn () => $this->svc->order($r, null, 'PO-1', $this->manager))->toThrow(DomainException::class);
    expect($r->fresh()->status)->toBe(PurchaseRequest::STATUS_REQUESTED);
});

it('cannot receive a request that was never ordered', function () {
    $r = pr();
    $this->svc->approve($r, null, $this->manager);

    expect(fn () => $this->svc->receive($r->fresh(), $this->buyer))->toThrow(DomainException::class);
    expect(onHandPrc())->toBe(0.0); // and no phantom stock appeared
});

it('walks Requested -> Approved -> Ordered -> Received', function () {
    $r = pr();

    expect($this->svc->approve($r, 'Agreed.', $this->manager)->status)->toBe(PurchaseRequest::STATUS_APPROVED);
    expect($this->svc->order($r->fresh(), null, 'PO-99', $this->manager)->status)->toBe(PurchaseRequest::STATUS_ORDERED);
    expect($this->svc->receive($r->fresh(), $this->buyer)->status)->toBe(PurchaseRequest::STATUS_RECEIVED);
});

it('sends a high-value request past the manager', function () {
    $r = pr([], [['inventory_item_id' => $this->item->id, 'quantity' => 400, 'unit_cost' => 50]]); // 20,000 -> tier_3

    expect(fn () => $this->svc->approve($r, null, $this->manager))->toThrow(DomainException::class);
    expect($this->svc->approve($r->fresh(), null, makeUser('super_admin', [$this->asset->id]))->status)
        ->toBe(PurchaseRequest::STATUS_APPROVED);
});

it('refuses to let the requester approve their own purchase', function () {
    // The requester must be someone who WOULD otherwise be allowed: a manager holds
    // procurement.decide and tier_1/2, so for a 500 request every other gate passes and only the
    // second-pair-of-eyes rule can stop them. Raising this as `operations` instead would prove
    // nothing — it dies on the missing decide permission long before self-approval is reached
    // (the first cut of this test did exactly that, and mutation testing caught it).
    $selfServer = $this->manager;
    $r = $this->svc->request([
        'asset_id' => $this->asset->id,
        'justification' => 'I need this and I approve of me.',
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 10, 'unit_cost' => 50]],
    ], $selfServer);

    expect($selfServer->can(PurchaseRequestService::DECIDE_PERMISSION))->toBeTrue();
    expect(ApprovalPolicy::canApprove($selfServer, ApprovalRule::MODULE_PURCHASE_REQUEST, 500.0))->toBeTrue();

    expect(fn () => $this->svc->approve($r, null, $selfServer))->toThrow(DomainException::class);
    expect($r->fresh()->status)->toBe(PurchaseRequest::STATUS_REQUESTED);

    // …and a different manager can.
    expect($this->svc->approve($r->fresh(), null, makeUser('manager', [$this->asset->id]))->status)
        ->toBe(PurchaseRequest::STATUS_APPROVED);
});

it('refuses an engineer deciding, and a viewer anything', function () {
    $r = pr();

    expect($this->buyer->can(PurchaseRequestService::DECIDE_PERMISSION))->toBeFalse();
    expect(fn () => $this->svc->reject($r, 'no', makeUser('viewer', [$this->asset->id])))->toThrow(DomainException::class);
    expect(fn () => $this->svc->receive($r, makeUser('viewer', [$this->asset->id])))->toThrow(DomainException::class);
});

it('judges the tier on the current total, not the frozen one', function () {
    // Lines can change after the request is raised. What matters is the value actually being
    // approved, not what it was worth when someone first clicked.
    $r = pr(); // 500 -> tier_1
    expect($r->required_permission)->toBe('approvals.tier_1');

    $r->lines()->create(['inventory_item_id' => $this->item->id, 'quantity' => 400, 'unit_cost' => 50]); // now 20,500
    expect((float) $r->fresh()->total_value)->toBe(20500.0);

    // A manager may approve tier_1/2 but this is now a tier_3 spend.
    expect(fn () => $this->svc->approve($r->fresh(), null, $this->manager))->toThrow(DomainException::class);
});

/* ---- FR-PROC-04 + FR-WH-02: receipt updates stock, linked --------------- */

it('stocks the goods on receipt and links the movement to the request', function () {
    // FR-WH-02: "log stock movements with ... linked work order or procurement reference".
    // This link is what the ad-hoc receipt path lacks, and why GRNI can never be cleared.
    $r = pr();
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-1', $this->manager);
    $received = $this->svc->receive($r->fresh(), $this->buyer);

    expect(onHandPrc())->toBe(10.0);

    $movement = StockMovement::where('inventory_item_id', $this->item->id)->sole();
    expect($movement->source_type)->toBe(PurchaseRequest::class);
    expect($movement->source_id)->toBe($received->id);
    expect($movement->reference)->toBe($received->reference);
    expect((float) $movement->unit_cost)->toBe(50.0);

    // …and the line points back at the movement it produced.
    expect($received->lines()->sole()->stock_movement_id)->toBe($movement->id);
});

it('does not stock a service line', function () {
    // The module covers "spare parts, consumables, and services" — a service is not stock.
    $r = pr([], [['description' => 'Annual chiller service visit', 'quantity' => 1, 'unit_cost' => 4000]]);
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-2', $this->manager);
    $this->svc->receive($r->fresh(), $this->buyer);

    expect(StockMovement::count())->toBe(0);
    expect($r->fresh()->status)->toBe(PurchaseRequest::STATUS_RECEIVED);
});

it('receives a mixed request, stocking only the stockable half', function () {
    $r = pr([], [
        ['inventory_item_id' => $this->item->id, 'quantity' => 10, 'unit_cost' => 50],
        ['description' => 'Callout fee', 'quantity' => 1, 'unit_cost' => 300],
    ]);
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-3', $this->manager);
    $this->svc->receive($r->fresh(), $this->buyer);

    expect(onHandPrc())->toBe(10.0);
    expect(StockMovement::count())->toBe(1);
});

it('never stocks the same line twice', function () {
    // `received` is terminal, so the transition matrix is the first guard; the per-line
    // stock_movement_id is the backstop if anything ever re-enters receive().
    $r = pr();
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-4', $this->manager);
    $this->svc->receive($r->fresh(), $this->buyer);

    expect(fn () => $this->svc->receive($r->fresh(), $this->buyer))->toThrow(DomainException::class);
    expect(onHandPrc())->toBe(10.0);
});

it('refuses to receive stockable goods with nowhere to put them', function () {
    $r = pr(['warehouse_id' => null]);
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-5', $this->manager);

    expect(fn () => $this->svc->receive($r->fresh(), $this->buyer))->toThrow(DomainException::class);
    expect(onHandPrc())->toBe(0.0);
});

it('refuses another property\'s warehouse — at creation, with receive() as the backstop', function () {
    // The mall that requested it is the mall that receives it. The model gate now refuses a
    // cross-property warehouse at CREATION (audit M29-2, the stronger guard); receive() remains the
    // backstop for a request somehow forced into that state.
    $other = makeAsset(['code' => 'OTH']);
    $foreign = Warehouse::create(['asset_id' => $other->id, 'name' => 'Theirs', 'code' => 'W9']);

    // Primary guard: it can't even be raised with a foreign warehouse.
    expect(fn () => pr(['warehouse_id' => $foreign->id]))->toThrow(DomainException::class);

    // Backstop: force the invalid state past the model hook (raw update), then receive() still refuses.
    $r = pr();
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-6', $this->manager);
    \Illuminate\Support\Facades\DB::table('purchase_requests')->where('id', $r->id)->update(['warehouse_id' => $foreign->id]);

    expect(fn () => $this->svc->receive($r->fresh(), $this->buyer))->toThrow(DomainException::class);
    expect(onHandPrc())->toBe(0.0);
});

it('lets a service-only request be received with no warehouse at all', function () {
    // The guard must not block the case that legitimately has nowhere to land.
    $r = pr(['warehouse_id' => null], [['description' => 'Service visit', 'quantity' => 1, 'unit_cost' => 900]]);
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-7', $this->manager);

    expect($this->svc->receive($r->fresh(), $this->buyer)->status)->toBe(PurchaseRequest::STATUS_RECEIVED);
});

/* ---- FR-PROC-05: status history ---------------------------------------- */

it('keeps a status history of every step', function () {
    // FR-PROC-05 — "Requested → Approved → Ordered → Received". The activity log IS the history:
    // spatie v5 records the before/after in attribute_changes, so no bespoke table is needed.
    $r = pr();
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-8', $this->manager);
    $this->svc->receive($r->fresh(), $this->buyer);

    $history = Activity::where('log_name', 'purchase_request')
        ->where('subject_id', $r->id)->where('event', 'updated')->get()
        ->map(fn ($a) => $a->attribute_changes['attributes']['status'] ?? null)
        ->filter()->values()->all();

    expect($history)->toBe(['approved', 'ordered', 'received']);
});

it('records who rejected a request and why', function () {
    $r = pr();
    $rejected = $this->svc->reject($r, 'Buy the refurbished ones instead.', $this->manager);

    expect($rejected->status)->toBe(PurchaseRequest::STATUS_REJECTED);
    expect($rejected->decision_notes)->toBe('Buy the refurbished ones instead.');
    expect($rejected->decided_by_user_id)->toBe($this->manager->id);
    expect(PurchaseRequest::TRANSITIONS[PurchaseRequest::STATUS_REJECTED])->toBe([]); // terminal
});

it('can cancel an ordered request that never arrived', function () {
    $r = pr();
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-9', $this->manager);

    expect($this->svc->cancel($r->fresh(), 'Supplier cannot supply.', $this->manager)->status)
        ->toBe(PurchaseRequest::STATUS_CANCELLED);
    expect(onHandPrc())->toBe(0.0);
});

/* ---- line integrity ----------------------------------------------------- */

it('refuses a line that is both a catalog item and free text, or neither', function () {
    expect(fn () => pr([], [['inventory_item_id' => $this->item->id, 'description' => 'both', 'quantity' => 1, 'unit_cost' => 5]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => pr([], [['quantity' => 1, 'unit_cost' => 5]]))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a zero-cost catalog line at request time, not at receipt', function () {
    // Stock that arrives at zero value posts NOTHING to the GL (the journalizer returns null for a
    // zero-value movement), so inventory would inflate while the money never appears — which is why
    // the receipt path has always required minValue(0.01). Caught HERE because allowing it let a
    // request be raised, approved and ORDERED, and only then die at receipt, after the mall had
    // committed to buy it.
    expect(fn () => pr([], [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_cost' => 0]]))
        ->toThrow(InvalidArgumentException::class);

    // A SERVICE may legitimately be free — it never becomes stock, so there is nothing to value.
    $free = pr([], [['description' => 'Warranty visit, no charge', 'quantity' => 1, 'unit_cost' => 0]]);
    expect((float) $free->total_value)->toBe(0.0);
    $this->svc->approve($free, null, $this->manager);
    $this->svc->order($free->fresh(), null, 'PO-FREE', $this->manager);
    expect($this->svc->receive($free->fresh(), $this->buyer)->status)->toBe(PurchaseRequest::STATUS_RECEIVED);
});

it('refuses a non-positive quantity or a negative cost', function () {
    expect(fn () => pr([], [['inventory_item_id' => $this->item->id, 'quantity' => 0, 'unit_cost' => 5]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => pr([], [['inventory_item_id' => $this->item->id, 'quantity' => 1, 'unit_cost' => -5]]))
        ->toThrow(InvalidArgumentException::class);
});
