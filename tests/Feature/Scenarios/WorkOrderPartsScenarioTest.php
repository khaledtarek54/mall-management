<?php

use App\Support\MorphMap;
use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderPart;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\FacilityWorkOrderService;
use App\Services\StockMovementService;
use App\Services\WorkOrderPartService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spare parts on a work order — FR-CM-09 (internal vs external), FR-CM-10 (approval for an
 * internal draw), FR-CM-11 (which approver depends on the value), FR-INV-04 (record which).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    $this->svc = app(WorkOrderPartService::class);
    $this->wos = app(FacilityWorkOrderService::class);
    $this->asset = makeAsset(['code' => 'PRT']);
    $this->warehouse = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Main', 'code' => 'W1']);
    $this->item = InventoryItem::create(['sku' => 'PMP-SEAL', 'name' => 'Pump seal', 'unit' => 'each', 'unit_cost' => 100]);

    // 100 units on hand.
    app(StockMovementService::class)->record([
        'warehouse_id' => $this->warehouse->id, 'inventory_item_id' => $this->item->id,
        'type' => 'receipt', 'quantity' => 100, 'unit_cost' => 100,
    ]);
});

function partOrder(): FacilityWorkOrder
{
    return FacilityWorkOrder::create([
        'asset_id' => test()->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'description' => 'Pump leaking', 'title' => 'Fix pump', 'category' => 'plumbing',
        'scheduled_for' => '2026-07-01',
    ]);
}

function onHand(): float
{
    return (float) StockMovement::where('inventory_item_id', test()->item->id)->sum('quantity');
}

/* ---- FR-CM-10: a draw is requested, not taken --------------------------- */

it('does not move stock when a part is requested', function () {
    // The heart of FR-CM-10: approval must come BEFORE the stock leaves the shelf.
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], makeUser('operations', [$this->asset->id])->id);

    expect($part->status)->toBe(FacilityWorkOrderPart::STATUS_PENDING);
    expect($part->stock_movement_id)->toBeNull();
    expect(onHand())->toBe(100.0); // untouched
});

it('moves the stock only once the draw is approved', function () {
    $engineer = makeUser('operations', [$this->asset->id]);
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], $engineer->id);

    $approved = $this->svc->approve($part, makeUser('manager', [$this->asset->id]));

    expect($approved->status)->toBe(FacilityWorkOrderPart::STATUS_APPROVED);
    expect($approved->stock_movement_id)->not->toBeNull();
    expect(onHand())->toBe(98.0);
    // The movement points back at the job — the ledger says what the stock was for.
    expect($approved->movement->source_id)->toBe($approved->facility_work_order_id);
    expect($approved->movement->source_type)->toBe(MorphMap::alias(FacilityWorkOrder::class));
});

it('moves no stock when a draw is rejected', function () {
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], makeUser('operations', [$this->asset->id])->id);

    $rejected = $this->svc->reject($part, 'Use the refurbished one first.', makeUser('manager', [$this->asset->id]));

    expect($rejected->status)->toBe(FacilityWorkOrderPart::STATUS_REJECTED);
    expect($rejected->decision_notes)->toBe('Use the refurbished one first.');
    expect(onHand())->toBe(100.0);
});

/* ---- FR-CM-11: the approver depends on the value ------------------------ */

it('lets a supervisor approve a low-value draw', function () {
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2, // 200
    ], makeUser('operations', [$this->asset->id])->id);

    expect($part->required_permission)->toBe('approvals.tier_1');
    expect($this->svc->approve($part, makeUser('operations', [$this->asset->id]))->status)
        ->toBe(FacilityWorkOrderPart::STATUS_APPROVED);
});

it('refuses a supervisor on a high-value draw', function () {
    // The whole point of FR-CM-11: higher value, higher authority.
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 60, // 6,000
    ], makeUser('operations', [$this->asset->id])->id);

    expect($part->required_permission)->toBe('approvals.tier_2');
    expect(fn () => $this->svc->approve($part, makeUser('operations', [$this->asset->id])))
        ->toThrow(DomainException::class);
    expect(onHand())->toBe(100.0);

    // A manager can.
    expect($this->svc->approve($part->fresh(), makeUser('manager', [$this->asset->id]))->status)
        ->toBe(FacilityWorkOrderPart::STATUS_APPROVED);
});

it('escalates a very high-value draw past the manager', function () {
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 200, // 20,000
    ], makeUser('operations', [$this->asset->id])->id);

    expect($part->required_permission)->toBe('approvals.tier_3');
    expect(fn () => $this->svc->approve($part, makeUser('manager', [$this->asset->id])))
        ->toThrow(DomainException::class);
    // Note the on-hand is only 100 — this asserts the AUTHORITY gate fires before the
    // stock check, so the user is told why, not just "not enough stock".
});

it('refuses a low-tier user rejecting a high-value draw', function () {
    // Refusing is as much an act of authority as approving.
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 60,
    ], makeUser('operations', [$this->asset->id])->id);

    expect(fn () => $this->svc->reject($part, 'no', makeUser('operations', [$this->asset->id])))
        ->toThrow(DomainException::class);
});

it('refuses to let the requester approve their own draw', function () {
    // The FRD asks for a MANAGER's sign-off — the control is a second pair of eyes.
    // Without this an engineer holding tier_1 self-serves every low-value part.
    $engineer = makeUser('operations', [$this->asset->id]);
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], $engineer->id);

    expect(fn () => $this->svc->approve($part, $engineer))->toThrow(DomainException::class);
    expect(onHand())->toBe(100.0);
});

it('refuses an unauthenticated decision', function () {
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], makeUser('operations', [$this->asset->id])->id);

    // No actor passed and nobody signed in — must not fall through to "approved by null".
    expect(fn () => $this->svc->approve($part))->toThrow(DomainException::class);
    expect(onHand())->toBe(100.0);
});

it('freezes the tier that was required at request time', function () {
    // The record must still say who was SUPPOSED to sign it off after the bands change.
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], makeUser('operations', [$this->asset->id])->id);

    ApprovalRule::query()->update(['required_permission' => 'approvals.tier_3']);

    expect($part->fresh()->required_permission)->toBe('approvals.tier_1');
});

it('freezes the unit cost at request time', function () {
    // Re-reading the catalog at approval would restate the value a manager signed off —
    // and the value is what decides which manager.
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], makeUser('operations', [$this->asset->id])->id);

    $this->item->update(['unit_cost' => 9999]);

    expect((float) $part->fresh()->unit_cost)->toBe(100.0);
    expect((float) $part->fresh()->value)->toBe(200.0);
});

/* ---- FR-CM-09 / FR-INV-04: internal vs external ------------------------- */

it('records an outside purchase without approval or a stock movement', function () {
    // FR-CM-10 scopes approval to parts drawn FROM INTERNAL INVENTORY. Buying outside is
    // procurement's problem — gating it here would gate a purchase that already happened.
    $part = $this->svc->recordExternal(partOrder(), [
        'description' => 'Bespoke gasket, cut to order', 'quantity' => 1, 'unit_cost' => 750,
        'reference' => 'INV-8891',
    ], makeUser('operations', [$this->asset->id])->id);

    expect($part->status)->toBe(FacilityWorkOrderPart::STATUS_RECORDED);
    expect($part->source)->toBe(FacilityWorkOrderPart::SOURCE_EXTERNAL);
    expect($part->stock_movement_id)->toBeNull();
    expect(onHand())->toBe(100.0);
    expect((float) $part->value)->toBe(750.0);
});

it('answers "what did we buy outside?" — the question that was previously unanswerable', function () {
    // An externally bought part used to be simply ABSENT from the system: internal was
    // implied by a StockMovement existing, external by nothing at all.
    $order = partOrder();
    $this->svc->recordExternal($order, ['description' => 'Bespoke gasket', 'quantity' => 1, 'unit_cost' => 750], makeUser('operations')->id);
    $this->svc->requestInternal($order, ['inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2], makeUser('operations')->id);

    expect(FacilityWorkOrderPart::where('source', 'external')->sum('value'))->toEqual(750);
    expect(FacilityWorkOrderPart::where('source', 'internal')->count())->toBe(1);
});

it('counts only parts that actually cost the job something', function () {
    $order = partOrder();
    $engineer = makeUser('operations', [$this->asset->id]);
    $manager = makeUser('manager', [$this->asset->id]);

    $approved = $this->svc->requestInternal($order, ['inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2], $engineer->id);
    $this->svc->approve($approved, $manager);
    $rejected = $this->svc->requestInternal($order, ['inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 5], $engineer->id);
    $this->svc->reject($rejected, 'no', $manager);
    $this->svc->recordExternal($order, ['description' => 'Gasket', 'quantity' => 1, 'unit_cost' => 750], $engineer->id);
    $pending = $this->svc->requestInternal($order, ['inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1], $engineer->id);

    // 200 approved + 750 external. The rejected 500 and the pending 100 cost nothing.
    expect($order->fresh()->partsCost())->toBe(950.0);
    expect($pending->status)->toBe(FacilityWorkOrderPart::STATUS_PENDING);
});

/* ---- integrity ---------------------------------------------------------- */

it('refuses to decide a request twice', function () {
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], makeUser('operations')->id);
    $manager = makeUser('manager', [$this->asset->id]);
    $this->svc->approve($part, $manager);

    // Otherwise a double-click draws the stock twice.
    expect(fn () => $this->svc->approve($part->fresh(), $manager))->toThrow(DomainException::class);
    expect(onHand())->toBe(98.0);
});

it('will not draw more than is on the shelf', function () {
    // StockMovementService re-checks on-hand under its own lock, so an approval racing the
    // last unit still cannot drive stock negative.
    $part = $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 500,
    ], makeUser('operations')->id);

    expect(fn () => $this->svc->approve($part, makeUser('super_admin')))
        ->toThrow(HttpException::class);
    expect(onHand())->toBe(100.0);
    expect($part->fresh()->status)->toBe(FacilityWorkOrderPart::STATUS_PENDING);
});

it('refuses to add parts to a closed job', function () {
    $order = partOrder();
    $this->wos->transition($order, 'cancelled');

    expect(fn () => $this->svc->requestInternal($order->fresh(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1,
    ], makeUser('operations')->id))->toThrow(DomainException::class);
});

it('refuses an internal part with no item or warehouse', function () {
    expect(fn () => FacilityWorkOrderPart::create([
        'facility_work_order_id' => partOrder()->id, 'source' => 'internal', 'quantity' => 1,
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses an external part with nothing describing what was bought', function () {
    // It has no SKU in our catalog — that is what makes it external — so the description is
    // the only record of what it was.
    expect(fn () => FacilityWorkOrderPart::create([
        'facility_work_order_id' => partOrder()->id, 'source' => 'external', 'quantity' => 1, 'unit_cost' => 10,
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses a zero quantity', function () {
    expect(fn () => $this->svc->requestInternal(partOrder(), [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 0,
    ], makeUser('operations')->id))->toThrow(InvalidArgumentException::class);
});
