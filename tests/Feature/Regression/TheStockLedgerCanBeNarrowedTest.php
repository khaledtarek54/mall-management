<?php

use App\Filament\Admin\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\SavedViews;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * **An append-only register you cannot narrow is a register you cannot audit** (SW-198,
 * 2026-09-04).
 *
 * The stock ledger carried ONE filter — movement type — which the tabs above it already offer. So
 * *"what left the Consumables store in March"* — the question a storeman is asked at every stock
 * count, and the only way to explain a variance — meant scrolling an append-only table that grows
 * for ever, or opening the database. Every other dated register in the panel (custodies, payments,
 * expenses) has carried a date range for months.
 *
 * Three filters, and the two record ones are `EntitySelectFilter`s so the dropdown and its chip
 * read exactly like the pickers on the Receive/Adjust modals.
 *
 * The CSV had to move with them. `movementsCsv()` read `getEloquentQuery()` under a comment
 * promising it *"reads the same property-scoped query the table shows so the export can never
 * disagree with the screen"* — true when there was nothing to disagree about, false the moment a
 * filter exists, and silently so: the operator narrows to one store, exports, and gets the whole
 * portfolio's ledger with no indication that the file is not what they were looking at.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mall = makeAsset(['code' => 'LDG']);
    $this->storeA = Warehouse::create(['asset_id' => $this->mall->id, 'name' => 'Parts store', 'code' => 'LDG-1', 'is_active' => true]);
    $this->storeB = Warehouse::create(['asset_id' => $this->mall->id, 'name' => 'Consumables store', 'code' => 'LDG-2', 'is_active' => true]);

    $this->filter = InventoryItem::create(['sku' => 'FILT', 'name' => 'HVAC filter', 'unit' => 'each', 'unit_cost' => 50, 'reorder_level' => 0, 'is_active' => true]);
    $this->bulb = InventoryItem::create(['sku' => 'LMP', 'name' => 'LED lamp', 'unit' => 'each', 'unit_cost' => 20, 'reorder_level' => 0, 'is_active' => true]);

    $this->january = StockMovement::create([
        'warehouse_id' => $this->storeA->id, 'inventory_item_id' => $this->filter->id,
        'type' => 'receipt', 'quantity' => 10, 'unit_cost' => 50, 'moved_on' => '2026-01-10',
    ]);

    $this->march = StockMovement::create([
        'warehouse_id' => $this->storeB->id, 'inventory_item_id' => $this->bulb->id,
        'type' => 'receipt', 'quantity' => 4, 'unit_cost' => 20, 'moved_on' => '2026-03-18',
    ]);

    $this->actingAs(makeUser('super_admin', [$this->mall->id]));
});

it('narrows the ledger to one store', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListStockMovements::class)
            ->filterTable('warehouse_id', $this->storeB->id)
            ->assertCanSeeTableRecords([$this->march])
            ->assertCanNotSeeTableRecords([$this->january]);
    });
});

it('narrows the ledger to one item', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListStockMovements::class)
            ->filterTable('inventory_item_id', $this->filter->id)
            ->assertCanSeeTableRecords([$this->january])
            ->assertCanNotSeeTableRecords([$this->march]);
    });
});

it('narrows the ledger to a date range, at both ends', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListStockMovements::class)
            ->filterTable('moved_on', ['from' => '2026-03-01', 'until' => '2026-03-31'])
            ->assertCanSeeTableRecords([$this->march])
            ->assertCanNotSeeTableRecords([$this->january]);

        // The other end, because a range with only one working bound reads as a working filter.
        Livewire::test(ListStockMovements::class)
            ->filterTable('moved_on', ['from' => null, 'until' => '2026-02-28'])
            ->assertCanSeeTableRecords([$this->january])
            ->assertCanNotSeeTableRecords([$this->march]);
    });
});

it('shows the whole ledger when nothing is asked', function () {
    // The control: filters that hid everything would satisfy all three refusals above.
    asTenant($this->mall, function () {
        Livewire::test(ListStockMovements::class)
            ->assertCanSeeTableRecords([$this->january, $this->march]);
    });
});

it('exports the ledger the operator is looking at, not the whole register', function () {
    asTenant($this->mall, function () {
        $page = Livewire::test(ListStockMovements::class)
            ->filterTable('warehouse_id', $this->storeB->id)
            ->callAction('export_csv')
            ->assertFileDownloaded('stock-movements.csv');

        $csv = base64_decode((string) data_get($page->effects, 'download.content'));

        expect($csv)->toContain('Consumables store')
            ->and($csv)->not->toContain('Parts store');
    });
});

it('offers the saved-view menu now that the ledger carries a standing question', function () {
    // Three filters is the threshold `SavedViews` sets, and its conformance gate fails in BOTH
    // directions — so adding the filters without mounting the trait would turn the build red.
    expect(SavedViews::offeredBy(StockMovementResource::class))->toBeTrue()
        ->and(SavedViews::mountedBy(StockMovementResource::class))->toBeTrue();
});
