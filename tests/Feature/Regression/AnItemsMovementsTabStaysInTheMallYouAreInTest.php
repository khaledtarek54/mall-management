<?php

use App\Filament\Admin\RelationManagers\StockMovementsRelationManager;
use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Admin\Resources\InventoryItems\Pages\EditInventoryItem;
use App\Filament\Admin\Resources\Warehouses\Pages\EditWarehouse;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * SW-191 — the item's Stock Movements tab is scoped to the property you are in.
 *
 * `InventoryItem` is `#[PortfolioShared]` on purpose: a pump seal is the same part in every mall.
 * Stock is not. `InventoryItemResource::getEloquentQuery()` has narrowed both `on_hand` and
 * `stock_value` to `TenantScope::reportAssetIds()` since FR-INV-01 was closed — the fix whose own
 * note says an operator restricted to mall A read "100 on hand" for an item mall A had none of.
 *
 * The tab underneath that figure was the half nobody scoped. `$relationship` is the bare
 * `movements` hasMany, so on an ITEM parent it listed every mall's receipts, consumptions and
 * adjustments — to any operator who could open the item — and the two halves of ONE screen could
 * not be reconciled with each other. That is the worst place for it: the tab is where somebody goes
 * precisely BECAUSE they doubt the number beside it.
 *
 * The seeded books cannot show this (`mall_management_qa` holds all 17 stock movements in one
 * property), which is why this builds the two-mall case by hand.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mallA = makeAsset(['code' => 'SW191A']);
    $this->mallB = makeAsset(['code' => 'SW191B']);

    $this->storeA = Warehouse::create([
        'asset_id' => $this->mallA->id, 'code' => 'SW191-WA', 'name' => 'Mall A store',
    ]);
    $this->storeB = Warehouse::create([
        'asset_id' => $this->mallB->id, 'code' => 'SW191-WB', 'name' => 'Mall B store',
    ]);

    $this->item = InventoryItem::create([
        'sku' => 'SW191-SEAL',
        'name' => 'Pump seal',
        'unit' => 'each',
        'unit_cost' => 100,
        'reorder_level' => 0,
        'is_active' => true,
    ]);

    $this->receiptA = StockMovement::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->storeA->id,
        'type' => 'receipt', 'quantity' => 10, 'unit_cost' => 100,
        'moved_on' => now()->subDay(), 'reference' => 'SW191-A',
    ]);

    $this->receiptB = StockMovement::create([
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->storeB->id,
        'type' => 'receipt', 'quantity' => 25, 'unit_cost' => 100,
        'moved_on' => now(), 'reference' => 'SW191-B',
    ]);

    // Assigned to BOTH malls deliberately: nothing here may turn on what this operator is ALLOWED
    // to reach, only on which property is selected. Pinning them to mall A alone would let the
    // refusal below pass for the wrong reason — `AssignedAssets` would already have stopped them.
    $this->actingAs(makeUser('operations', [$this->mallA->id, $this->mallB->id]));
});

function sw191MovementTabRows(object $ownerRecord, string $pageClass, array $extra = []): Collection
{
    return tableRows(Livewire::test(StockMovementsRelationManager::class, array_merge([
        'ownerRecord' => $ownerRecord,
        'pageClass' => $pageClass,
    ], $extra)));
}

it('lists an item only the movements from the property in the switcher', function () {
    asTenant($this->mallA, function () {
        expect(sw191MovementTabRows($this->item, EditInventoryItem::class)->pluck('id')->all())
            ->toBe([$this->receiptA->id]);
    });
});

it('lists the other mall\'s movement once the operator switches to it', function () {
    // THE CONTROL. A scope that returned nothing at all would satisfy the refusal above and read as
    // a pass — this is the assertion that goes red if the tab is simply emptied.
    asTenant($this->mallB, function () {
        expect(sw191MovementTabRows($this->item, EditInventoryItem::class)->pluck('id')->all())
            ->toBe([$this->receiptB->id]);
    });
});

it('ties the tab to the on-hand figure the register shows for the same item', function () {
    // The reported symptom, stated as the arithmetic it broke: on-hand is the sum of the movements
    // that make it up. 10 received in mall A, 25 in mall B.
    asTenant($this->mallA, function () {
        $onHand = (float) InventoryItemResource::getEloquentQuery()
            ->whereKey($this->item->id)->first()->on_hand;

        $fromTab = (float) sw191MovementTabRows($this->item, EditInventoryItem::class)
            ->sum(fn (StockMovement $m): float => (float) $m->quantity);

        expect($onHand)->toBe(10.0)
            ->and($fromTab)->toBe($onHand);
    });
});

it('still lists a warehouse\'s own movements on the warehouse tab', function () {
    // The class has TWO parents (`movements` is the relation on Warehouse as well as on
    // InventoryItem) and only the item side leaked. Narrowing by anything other than the
    // movement's OWN warehouse would break this one, which is the second half of the seam.
    asTenant($this->mallA, function () {
        expect(sw191MovementTabRows($this->storeA, EditWarehouse::class)->pluck('id')->all())
            ->toBe([$this->receiptA->id]);
    });
});
