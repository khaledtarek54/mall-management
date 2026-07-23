<?php

use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Module 22 UX pass — the stock register and the movement ledger are exportable to CSV, the format
 * an accountant actually reconciles in. The valuation (on_hand × unit cost) must match what the
 * table shows, must be property-scoped exactly like on_hand, and must close with a portfolio total.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mallA = makeAsset(['code' => 'AAA']);
    $this->mallB = makeAsset(['code' => 'BBB']);
    $this->whA = Warehouse::create(['asset_id' => $this->mallA->id, 'name' => 'A store', 'code' => 'WA']);
    $this->whB = Warehouse::create(['asset_id' => $this->mallB->id, 'name' => 'B store', 'code' => 'WB']);
    $this->item = InventoryItem::create(['sku' => 'FILT', 'name' => 'Filter', 'category' => 'HVAC', 'unit' => 'each', 'unit_cost' => 50, 'reorder_level' => 10]);
});

function receive(Warehouse $wh, float $qty): void
{
    app(StockMovementService::class)->record([
        'warehouse_id' => $wh->id, 'inventory_item_id' => test()->item->id,
        'type' => 'receipt', 'quantity' => $qty, 'unit_cost' => 50,
    ]);
}

it('values the stock register at on-hand × unit cost, scoped to the user', function () {
    receive($this->whA, 7);
    receive($this->whB, 100);
    $this->actingAs(makeUser('manager', [$this->mallA->id]));

    $csv = InventoryItemResource::stockRegisterCsv();
    $itemRow = collect($csv['rows'])->firstWhere(0, 'FILT');

    // 7 on hand (mall A only, NOT the 107 across both) × 50 = 350.
    expect((float) $itemRow[4])->toBe(7.0)
        ->and((float) $itemRow[6])->toBe(350.0);
});

it('closes the register with a portfolio total valuation', function () {
    receive($this->whA, 7);
    receive($this->whB, 100);
    $this->actingAs(makeUser('super_admin', [$this->mallA->id, $this->mallB->id]));

    $csv = InventoryItemResource::stockRegisterCsv();
    $totalRow = collect($csv['rows'])->last();

    // 107 on hand across both malls × 50 = 5350, in the final total row.
    expect((float) $totalRow[6])->toBe(5350.0);
});

it('exports the movement ledger scoped to the user', function () {
    receive($this->whA, 7);
    receive($this->whB, 100);
    $this->actingAs(makeUser('manager', [$this->mallA->id]));

    $csv = StockMovementResource::movementsCsv();

    // Only mall A's single receipt — mall B's movement is out of scope.
    expect($csv['rows'])->toHaveCount(1)
        ->and((float) $csv['rows'][0][4])->toBe(7.0);
});
