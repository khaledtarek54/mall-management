<?php

use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\StockMovementService;
use App\Support\ApprovalPolicy;
use App\Support\MorphMap;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ApprovalRulesSeeder;
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
 *
 * ── UPDATED 2026-08-11 (module 22 close-out) ──────────────────────────────────────────────────
 * Two of these tests described a state that is no longer reachable, and the change is the point.
 * The cost-out fallback no longer reads the item's CURRENT catalogue price — it reads the
 * **weighted-average cost of what is on hand**, derived from the movement ledger
 * (`StockMovementService::weightedAverageCost()`). So an item stocked at 200 with a catalogue
 * price of 0 now issues at **200**: the value follows the stock, which is exactly what F-83 asked
 * for, arriving from a better source than the field somebody forgot to fill in.
 *
 * The guard itself stays and still fires — for stock that has genuinely never carried a value
 * anywhere (nothing received, catalogue 0), which is the only case left where nothing can be
 * derived. Those are the two tests below, rewritten to assert the outcome rather than the
 * mechanism, so they keep proving "stock never moves without its value" without pinning the
 * particular fallback that supplies it.
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

it('values a consumption from what the stock was received at, not the catalogue price', function () {
    // Stock it at a real cost — so on-hand exists and only the CATALOG price is 0. This used to
    // throw: the fallback read the catalogue's 0 and F-83 refused the movement. The stock now
    // carries its value in the ledger, so the movement is valued rather than refused, which is
    // what "stock never moves without its value" always meant.
    app(StockMovementService::class)->record([
        'type' => 'receipt', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => 10, 'unit_cost' => 200,
        'moved_on' => now()->toDateString(),
    ]);

    $movement = app(StockMovementService::class)->record([
        'type' => 'consumption', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => 10,
        'moved_on' => now()->toDateString(),
    ]);

    expect((float) $movement->unit_cost)->toBe(200.0);
});

it('STILL refuses to move stock that has never carried a value anywhere', function () {
    // The F-83 guard, on the only case left where nothing can be derived: catalogue 0 and nothing
    // ever received, so there is no loaded cost to average. Without this the guard would have
    // been quietly retired by the cost-basis change rather than deliberately narrowed.
    expect(fn () => app(StockMovementService::class)->record([
        'type' => 'adjustment', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => -1,
        'moved_on' => now()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class);

    expect(StockMovement::where('type', 'adjustment')->count())->toBe(0);
});

it('values a write-off at the loaded cost, so it always hits Inventory Adjustment', function () {
    // The doc promises a write-off ALWAYS hits Inventory Adjustment. At catalogue cost 0 it used
    // to post nothing, and F-83 turned that into a refusal. Now it posts the real 200 x 5.
    app(StockMovementService::class)->record([
        'type' => 'receipt', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => 5, 'unit_cost' => 200,
        'moved_on' => now()->toDateString(),
    ]);

    $writeOff = app(StockMovementService::class)->record([
        'type' => 'adjustment', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->freeItem->id, 'quantity' => -5,
        'moved_on' => now()->toDateString(),
    ]);

    expect((float) $writeOff->unit_cost)->toBe(200.0);
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
    expect(JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))
        ->where('source_id', $consumption->id)->where('status', 'posted')->exists())->toBeTrue();
});

it('does not let a zero-cost item collapse the approval ladder to its lowest tier', function () {
    // The second blast radius, stated directly: the tier the ladder demands is driven by the
    // draw's VALUE, so a 0-cost catalog item made a 50,000 draw look free and ask for tier_1.
    $this->seed(ApprovalRulesSeeder::class);

    $module = ApprovalRule::MODULE_INVENTORY_DRAW;

    $free = ApprovalPolicy::permissionFor($module, 0.0);
    $real = ApprovalPolicy::permissionFor($module, 50000.0);

    expect($free)->toBe(ApprovalRule::TIER_1)
        ->and($real)->toBe(ApprovalRule::TIER_3)
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
