<?php

use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * FR-INV-01 — "track spare parts stock levels **per mall/location**".
 *
 * THE BUG (fixed 2026-07-16). InventoryItemResource summed `movements` across EVERY warehouse in
 * the portfolio, and the reorder colour compared that portfolio total against the item's reorder
 * level. `inventory_items` is a deliberately SHARED catalog, so nothing else scoped it either.
 *
 * Two failures in one: a property **leak** (a manager restricted to mall A read mall B's
 * quantities), and a **wrong number** (mall A saw on_hand = 100 for an item it had NONE of, painted
 * green as "well stocked"). Any FR-INV-03 low-stock alert built on that figure inherits the lie —
 * which is why this had to land before the alerts, not after.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mallA = makeAsset(['code' => 'AAA']);
    $this->mallB = makeAsset(['code' => 'BBB']);
    $this->whA = Warehouse::create(['asset_id' => $this->mallA->id, 'name' => 'A store', 'code' => 'WA']);
    $this->whB = Warehouse::create(['asset_id' => $this->mallB->id, 'name' => 'B store', 'code' => 'WB']);
    $this->item = InventoryItem::create(['sku' => 'FILT', 'name' => 'Filter', 'unit' => 'each', 'unit_cost' => 50, 'reorder_level' => 10]);
});

function stockIn(Warehouse $wh, float $qty): void
{
    app(StockMovementService::class)->record([
        'warehouse_id' => $wh->id, 'inventory_item_id' => test()->item->id,
        'type' => 'receipt', 'quantity' => $qty, 'unit_cost' => 50,
    ]);
}

function onHandFor(): ?float
{
    $row = InventoryItemResource::getEloquentQuery()->find(test()->item->id);

    return $row->on_hand === null ? 0.0 : (float) $row->on_hand;
}

it('does not show one mall another mall\'s stock', function () {
    // The proof: mall B holds 100, mall A holds nothing.
    stockIn($this->whB, 100);
    $this->actingAs(makeUser('manager', [$this->mallA->id]));

    expect(onHandFor())->toBe(0.0);
});

it('marks an item low for the mall that is actually out', function () {
    // The consequence that matters: before the fix this read 100 and painted green — telling the
    // one mall that had run out that it was well stocked.
    stockIn($this->whB, 100);
    $this->actingAs(makeUser('manager', [$this->mallA->id]));

    $row = InventoryItemResource::getEloquentQuery()->find($this->item->id);
    $onHand = (float) ($row->on_hand ?? 0);

    expect($onHand <= (float) $row->reorder_level)->toBeTrue('mall A is out; it must read as low');
});

it('counts only the properties a restricted user can see', function () {
    stockIn($this->whA, 7);
    stockIn($this->whB, 100);
    $this->actingAs(makeUser('manager', [$this->mallA->id]));

    expect(onHandFor())->toBe(7.0);
});

it('sums both malls for a user who can see both', function () {
    // Scoping must not become under-reporting: a portfolio role in All-Properties mode is asking a
    // genuinely portfolio-wide question.
    stockIn($this->whA, 7);
    stockIn($this->whB, 100);
    $this->actingAs(makeUser('super_admin', [$this->mallA->id, $this->mallB->id]));

    expect(onHandFor())->toBe(107.0);
});

it('still nets consumption out of the right mall', function () {
    stockIn($this->whA, 10);
    app(StockMovementService::class)->record([
        'warehouse_id' => $this->whA->id, 'inventory_item_id' => $this->item->id,
        'type' => 'consumption', 'quantity' => 4, 'unit_cost' => 50,
    ]);
    $this->actingAs(makeUser('manager', [$this->mallA->id]));

    expect(onHandFor())->toBe(6.0);
});
