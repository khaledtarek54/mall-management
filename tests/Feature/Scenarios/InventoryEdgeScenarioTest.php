<?php

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Edge-case coverage for the inventory module (complements InventoryScenarioTest's
 * happy-path lifecycle): negative guards (no negative on-hand), zero-quantity
 * boundaries, warehouse property-scoping, and the shared-catalog vs property-owned
 * split (one InventoryItem, on-hand tracked per warehouse).
 */
beforeEach(function () {
    $this->stock = app(StockMovementService::class);
});

it('blocks a consumption that would drive on-hand negative (no negative stock)', function () {
    $asset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'FILTER-1', 'name' => 'Filter', 'unit' => 'each', 'unit_cost' => 12]);

    // Only 5 on hand.
    $this->stock->receive($warehouse, $item, 5, 12);
    expect($this->stock->onHand($item, $warehouse))->toBe(5.0);

    // Consuming 6 must be rejected (422) and must not write a movement.
    expect(fn () => $this->stock->record([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $item->id,
        'type' => 'consumption',
        'quantity' => 6,
        'unit_cost' => 12,
    ]))->toThrow(HttpException::class);

    // On-hand unchanged; only the single receipt row exists.
    expect($this->stock->onHand($item, $warehouse))->toBe(5.0);
    expect(StockMovement::where('inventory_item_id', $item->id)->where('type', 'consumption')->count())->toBe(0);
});

it('allows consuming exactly the full on-hand (boundary) leaving zero', function () {
    $asset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'BELT-1', 'name' => 'Belt', 'unit' => 'each', 'unit_cost' => 8]);

    $this->stock->receive($warehouse, $item, 7, 8);

    $consume = $this->stock->record([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $item->id,
        'type' => 'consumption',
        'quantity' => 7,
        'unit_cost' => 8,
    ]);

    expect((float) $consume->quantity)->toBe(-7.0); // sign forced to REMOVE
    expect($this->stock->onHand($item, $warehouse))->toBe(0.0);
});

it('rejects a zero-quantity receipt/consumption but permits a zero-quantity adjustment (boundary)', function () {
    $asset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'NUT-1', 'name' => 'Nut', 'unit' => 'each', 'unit_cost' => 1]);

    // Zero receipt — non-zero required for stock-moving types. A REFUSAL (an operator typed the
    // quantity), so a DomainException since SW-197 — see StockMovementService's class docblock.
    expect(fn () => $this->stock->receive($warehouse, $item, 0, 1))
        ->toThrow(DomainException::class);

    // Zero consumption — same rule.
    expect(fn () => $this->stock->record([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $item->id,
        'type' => 'consumption',
        'quantity' => 0,
    ]))->toThrow(DomainException::class);

    // A zero-quantity adjustment IS allowed (a signed correction may net to zero).
    $adjust = $this->stock->adjust($warehouse, $item, 0);
    expect((float) $adjust->quantity)->toBe(0.0);
    expect($this->stock->onHand($item, $warehouse))->toBe(0.0);
});

it('rejects an unknown movement type', function () {
    $asset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'WASH-1', 'name' => 'Washer', 'unit' => 'each', 'unit_cost' => 1]);

    expect(fn () => $this->stock->record([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $item->id,
        'type' => 'teleport',
        'quantity' => 3,
    ]))->toThrow(InvalidArgumentException::class);
});

it('scopes a warehouse to exactly one property, and on-hand is isolated per warehouse (item is a shared catalog)', function () {
    $assetA = makeAsset();
    $assetB = makeAsset();

    $whA = Warehouse::create(['asset_id' => $assetA->id, 'name' => 'A Store', 'code' => 'AST']);
    $whB = Warehouse::create(['asset_id' => $assetB->id, 'name' => 'B Store', 'code' => 'BST']);

    // Each warehouse belongs to its own property.
    expect($whA->asset_id)->toBe($assetA->id);
    expect($whB->asset_id)->toBe($assetB->id);

    // ONE shared catalog item stocked in both properties.
    $item = InventoryItem::create(['sku' => 'GLOVE-1', 'name' => 'Gloves', 'unit' => 'box', 'unit_cost' => 30]);

    $this->stock->receive($whA, $item, 40, 30);
    $this->stock->receive($whB, $item, 15, 30);

    // Per-warehouse on-hand is isolated; global sums across both.
    expect($this->stock->onHand($item, $whA))->toBe(40.0);
    expect($this->stock->onHand($item, $whB))->toBe(15.0);
    expect($this->stock->onHand($item, null))->toBe(55.0);

    // The item carries no asset_id — it is not property-owned; movements are.
    expect(in_array('asset_id', $item->getFillable(), true))->toBeFalse();
    expect($whA->movements()->count())->toBe(1);
    expect($whB->movements()->count())->toBe(1);
});

it('enforces the per-property unique warehouse code but allows the same code on another property', function () {
    $assetA = makeAsset();
    $assetB = makeAsset();

    Warehouse::create(['asset_id' => $assetA->id, 'name' => 'Main', 'code' => 'MAIN']);

    // Same code on a DIFFERENT property is fine (scope is asset_id + code).
    $other = Warehouse::create(['asset_id' => $assetB->id, 'name' => 'Main', 'code' => 'MAIN']);
    expect($other->exists)->toBeTrue();

    // Duplicate code within the SAME property violates the unique constraint.
    expect(fn () => Warehouse::create(['asset_id' => $assetA->id, 'name' => 'Dup', 'code' => 'MAIN']))
        ->toThrow(QueryException::class);
});

it('leaves on-hand and the ledger intact after a warehouse is soft-deleted (movements are historical facts)', function () {
    $asset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'CABLE-1', 'name' => 'Cable', 'unit' => 'm', 'unit_cost' => 4]);

    $this->stock->receive($warehouse, $item, 20, 4);
    trashBypassingDeletionPolicy($warehouse); // soft delete

    // The movement is NOT cascaded away; on-hand still counts it and the movement
    // still resolves its (trashed) warehouse for GL attribution.
    expect(StockMovement::where('warehouse_id', $warehouse->id)->count())->toBe(1);
    expect($this->stock->onHand($item, $warehouse))->toBe(20.0);
    expect(StockMovement::where('warehouse_id', $warehouse->id)->first()->warehouse->id)->toBe($warehouse->id);
});
