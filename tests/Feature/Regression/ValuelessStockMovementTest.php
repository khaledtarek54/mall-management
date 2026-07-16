<?php

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\StockMovementService;
use App\Support\ApprovalPolicy;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — gap-analysis **F-83**: stock must never move without its value.
 *
 * THE BUG. `unit_cost` was optional on the inventory item form (`minValue(0)`, `default(0)`).
 * `StockMovementService` values a consumption/adjustment at the item's standard cost when the
 * caller supplies none — but at cost 0 that fallback resolved to 0, and
 * `InventoryMovementJournalizer` returns `null` for a zero-value movement. So the stock left
 * the warehouse and **posted nothing**: Inventory inflates forever, the expense is never
 * charged, and the module doc's rule 7 ("a shrinkage write-off ALWAYS hits Inventory
 * Adjustment") was quietly false.
 *
 * The **receipt** path had already reasoned this through and guarded it — *"a 0-cost receipt
 * would add stock but post nothing to the GL"*, `minValue(0.01)->required()`. The cost-out side
 * never got the same guard. That is the round-2 pattern in one sentence: **a guard written once
 * tends not to get written twice.**
 *
 * SECOND BLAST RADIUS. `WorkOrderPartService::requestInternal` falls back to the same catalog
 * cost, so a 0-cost item priced a part draw at `value = 0` → `ApprovalPolicy::permissionFor(…,
 * 0)` → the **lowest** approval tier. Which is the very bug that service's own comment says it
 * fixed ("a 500 EGP draw priced itself at 0.00 and asked for tier_1") — `filled()` guards a
 * blank *submitted* value, but never a catalog cost of 0.
 *
 * The guard is keyed on QUANTITY, not type: a zero-quantity adjustment is a deliberate no-op
 * note and stays legal (see `StockMovementService`'s own `$quantity == 0.0 && $type !==
 * 'adjustment'` check, and the journalizer's "a zero-value movement has no GL effect").
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'VSM']);
    $this->warehouse = Warehouse::create([
        'asset_id' => $this->asset->id, 'code' => 'WH-VSM', 'name' => 'Main store',
    ]);
    // The bad data the form used to permit — and that legacy rows still carry.
    $this->freeItem = InventoryItem::create([
        'sku' => 'VSM-FREE', 'name' => 'Unpriced filter', 'unit' => 'each', 'unit_cost' => 0,
    ]);
});

it('refuses to consume stock that carries no value', function () {
    // Stock it first, at a real cost — so on-hand exists and only the CATALOG price is 0.
    app(StockMovementService::class)->record([
        'type' => 'receipt', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => 10, 'unit_cost' => 200,
        'moved_on' => now()->toDateString(),
    ]);

    expect(fn () => app(StockMovementService::class)->record([
        'type' => 'consumption', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => 10,
        'moved_on' => now()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class);

    // The stock is still there — it must not leave without its value following.
    expect(StockMovement::where('type', 'consumption')->count())->toBe(0);
});

it('refuses a write-off that carries no value', function () {
    // The doc promises a write-off ALWAYS hits Inventory Adjustment. At cost 0 it posted nothing.
    app(StockMovementService::class)->record([
        'type' => 'receipt', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => 5, 'unit_cost' => 200,
        'moved_on' => now()->toDateString(),
    ]);

    expect(fn () => app(StockMovementService::class)->record([
        'type' => 'adjustment', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => -5,
        'moved_on' => now()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class);
});

it('still allows a zero-quantity adjustment — a note is not a stock movement', function () {
    // The deliberate no-op the journalizer documents. The guard keys on quantity, not type,
    // precisely so this stays legal.
    $movement = app(StockMovementService::class)->record([
        'type' => 'adjustment', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => 0,
        'reference' => 'Stock-take: counted, no variance',
        'moved_on' => now()->toDateString(),
    ]);

    expect($movement->exists)->toBeTrue();
});

it('still consumes normally when the item carries a real cost', function () {
    // The guard must refuse the bad case WITHOUT breaking the real workflow.
    $item = InventoryItem::create(['sku' => 'VSM-OK', 'name' => 'Pump seal', 'unit' => 'each', 'unit_cost' => 25]);

    app(StockMovementService::class)->record([
        'type' => 'receipt', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 25,
        'moved_on' => now()->toDateString(),
    ]);

    $consumption = app(StockMovementService::class)->record([
        'type' => 'consumption', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $item->id, 'quantity' => 4,
        'moved_on' => now()->toDateString(),
    ]);

    expect((float) $consumption->unit_cost)->toBe(25.0); // the catalog fallback still works

    // ...and it genuinely reaches the ledger through the real sweep.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);
    expect(\App\Models\JournalEntry::where('source_type', StockMovement::class)
        ->where('source_id', $consumption->id)->where('status', 'posted')->exists())->toBeTrue();
});

it('does not let a zero-cost item collapse the approval ladder to its lowest tier', function () {
    // The second blast radius, stated directly: the tier the ladder demands is driven by the
    // draw's VALUE, so a 0-cost catalog item made a 50,000 draw look free and ask for tier_1.
    $this->seed(\Database\Seeders\ApprovalRulesSeeder::class);

    $module = \App\Models\ApprovalRule::MODULE_INVENTORY_DRAW;

    $free = ApprovalPolicy::permissionFor($module, 0.0);
    $real = ApprovalPolicy::permissionFor($module, 50000.0);

    expect($free)->toBe(\App\Models\ApprovalRule::TIER_1)
        ->and($real)->toBe(\App\Models\ApprovalRule::TIER_3)
        ->and($real)->not->toBe($free, 'a 50k draw must not ask for the same tier as a free one');

    // So a draw of the unpriced item can no longer be valued at 0 in the first place —
    // StockMovementService refuses it, and the form can no longer create such an item.
    expect((float) $this->freeItem->unit_cost)->toBe(0.0); // the legacy row still exists...
    expect(fn () => app(StockMovementService::class)->record([
        'type' => 'consumption', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => 1,
        'moved_on' => now()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class); // ...but it cannot move valueless.
});
