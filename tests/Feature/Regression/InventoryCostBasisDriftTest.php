<?php

use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Models\JournalLine;
use App\Services\Accounting\AccountResolver;
use App\Services\StockMovementService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Consumption must relieve Inventory at what the stock was LOADED at, not at whatever the item's
 * standard cost happens to say today.
 *
 * THE GAP (module 22 close-out, 2026-08-11). The module doc named this itself, as a "known
 * limitation … a future enhancement": receipts load Inventory at their entered purchase cost, while
 * consumption and adjustments relieve it at the item's *current* `unit_cost`
 * (`StockMovementService::record()` falls back to `InventoryItem::unit_cost` when the caller gives
 * none — which every real caller does, because nobody types a cost to issue a part they already own).
 *
 * A known limitation is only a decision if it is parked with a trigger and its damage is bounded.
 * This one is neither, and the damage is on the balance sheet:
 *
 *   receive 10 @ 100   → Dr Inventory 1,000
 *   edit the item cost to 300 (an ordinary act — prices rise, and the field exists to be edited)
 *   consume 10         → Cr Inventory 3,000
 *
 * On-hand is 0 and Inventory holds **−2,000**: a negative asset balance representing stock that
 * does not exist, with Repairs & Maintenance overstated by the same 2,000. The perpetual inventory
 * account cannot self-correct, because nothing re-derives it — every later movement compounds it.
 * It also flows straight into the owner statements, which are drawn off these balances.
 *
 * THE FIX is the standard perpetual answer and needs no new table: relieve at the **weighted-average
 * cost of what is actually on hand**, derived from the movement ledger the module already keeps.
 * `StockMovementService::weightedAverageCost()` is that derivation, and it is the single place the
 * question "what is this stock worth" is answered.
 *
 * The item's `unit_cost` keeps its real job — the default for a NEW receipt, i.e. what we expect to
 * pay next — which is what an operator editing it actually means.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->svc = app(StockMovementService::class);
    $this->asset = makeAsset(['code' => 'COST1']);
    $this->item = InventoryItem::create([
        'name' => 'Chiller filter', 'sku' => 'CF-1', 'unit' => 'pc',
        'unit_cost' => 100, 'reorder_level' => 0, 'is_active' => true,
    ]);
    $this->store = Warehouse::create([
        'asset_id' => $this->asset->id, 'name' => 'Main store', 'code' => 'MAIN',
        'category' => 'spare_parts', 'is_active' => true,
    ]);
});

/** Issue stock the way every real caller does: no unit_cost, letting the service value it. */
function consumeStock(Warehouse $warehouse, InventoryItem $item, float $quantity): void
{
    app(StockMovementService::class)->record([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $item->id,
        'type' => 'consumption',
        'quantity' => $quantity,
    ]);
}

/**
 * The Inventory asset balance for this property, straight out of the POSTED ledger — driven
 * through the real sweep, because a journalizer's arithmetic proves only itself (CLAUDE.md).
 */
function inventoryBalance(int $assetId): float
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $id = app(AccountResolver::class)->id('inventory', $assetId);

    $lines = JournalLine::where('ledger_account_id', $id);

    return round((float) $lines->sum('debit') - (float) (clone $lines)->sum('credit'), 2);
}

it('does not leave Inventory negative when the item cost is edited between receipt and consumption', function () {
    $this->svc->receive($this->store, $this->item, 10, 100);

    // An ordinary act: prices rise, and the field exists to be edited.
    $this->item->update(['unit_cost' => 300]);

    consumeStock($this->store, $this->item, 10);

    // Stock is gone, so Inventory must be flat — not −2,000.
    expect(round($this->svc->onHand($this->item, $this->store), 2))->toBe(0.0)
        ->and(inventoryBalance($this->asset->id))->toBe(0.0);
});

it('relieves a partial issue at the loaded cost, leaving the rest of the value behind', function () {
    $this->svc->receive($this->store, $this->item, 10, 100);
    $this->item->update(['unit_cost' => 300]);

    consumeStock($this->store, $this->item, 4);

    // 6 remain, loaded at 100 → Inventory holds exactly 600.
    expect(inventoryBalance($this->asset->id))->toBe(600.0);
});

it('averages across receipts at different prices, which is the point of the basis', function () {
    $this->svc->receive($this->store, $this->item, 10, 100);   // 1,000
    $this->svc->receive($this->store, $this->item, 10, 200);   // 2,000  → 20 on hand worth 3,000

    consumeStock($this->store, $this->item, 10);               // at 150 → relieves 1,500

    expect(round($this->svc->weightedAverageCost($this->item, $this->store), 2))->toBe(150.0)
        ->and(inventoryBalance($this->asset->id))->toBe(1500.0);
});

it('still honours an explicitly stated cost — a caller who knows better wins', function () {
    // The control, and a real need: a stock-take write-off valued by the auditor, or a
    // correction that must post at a stated figure.
    $this->svc->receive($this->store, $this->item, 10, 100);

    $movement = $this->svc->record([
        'warehouse_id' => $this->store->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'consumption',
        'quantity' => 2,
        'unit_cost' => 75,
    ]);

    expect((float) $movement->unit_cost)->toBe(75.0);
});

it('falls back to the item cost when there is nothing on hand to average', function () {
    // A negative adjustment against empty stock has no loaded value to derive from; the item's
    // standard cost is the only answer available, and is what the old behaviour always used.
    expect(round($this->svc->weightedAverageCost($this->item, $this->store), 2))->toBe(100.0);
});

it('leaves receipts alone — they load at what was actually paid', function () {
    // The other half of the invariant: this changes how stock is RELIEVED, never how it is loaded.
    $this->svc->receive($this->store, $this->item, 5, 250);

    expect(inventoryBalance($this->asset->id))->toBe(1250.0);
});

// ── The same root cause, one path further out ───────────────────────────────────────────────────

it('prices a work-order part draw from the stock on hand, not a stale catalogue price', function () {
    // `WorkOrderPartService::requestInternal()` fell back to `InventoryItem::unit_cost` for BOTH
    // things it decides, and both were wrong when the catalogue had drifted:
    //
    //   1. `value` picks the approval tier (FR-CM-11 — "which manager depends on the part's
    //      value"), so a catalogue price left at last year's figure routes a large draw to a
    //      junior approver. The mechanism whose whole job is to escalate, quietly not escalating.
    //   2. `approve()` then records the stock movement with that FROZEN unit_cost — an EXPLICIT
    //      cost, which means it walks straight past the weighted-average fallback in `record()`
    //      and re-introduces the very Inventory drift this file exists to close.
    //
    // Freezing at request time is right and stays: the manager approves a stated value. The bug
    // was only ever WHICH figure gets frozen.
    $this->svc->receive($this->store, $this->item, 10, 400);   // really worth 400 each
    $this->item->update(['unit_cost' => 100]);                 // catalogue drifted low

    $order = \App\Models\FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'title' => 'Chiller service', 'description' => 'Replace filters',
        'category' => 'hvac', 'scheduled_for' => '2026-08-01',
    ]);

    $part = app(\App\Services\WorkOrderPartService::class)->requestInternal($order, [
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->store->id,
        'quantity' => 5,
    ]);

    // 5 x 400 = 2,000 — the value a manager is actually being asked to sign off.
    expect((float) $part->unit_cost)->toBe(400.0)
        ->and((float) $part->value)->toBe(2000.0);
});

it('still lets the caller state a cost on a part draw', function () {
    // The control: an operator who knows the part cost something else must still be able to say so.
    $this->svc->receive($this->store, $this->item, 10, 400);

    $order = \App\Models\FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'title' => 'Chiller service', 'description' => 'Replace filters',
        'category' => 'hvac', 'scheduled_for' => '2026-08-01',
    ]);

    $part = app(\App\Services\WorkOrderPartService::class)->requestInternal($order, [
        'inventory_item_id' => $this->item->id,
        'warehouse_id' => $this->store->id,
        'quantity' => 5,
        'unit_cost' => 90,
    ]);

    expect((float) $part->unit_cost)->toBe(90.0);
});

// ── The screen has to agree with the ledger ─────────────────────────────────────────────────────

it('values the stock register at the same figure the GL holds', function () {
    // The register's value column is labelled, in its own comment, as "the number an operator"
    // reconciles with — and the page summariser calls it the accountant's total. It was
    // `on_hand × unit_cost`: the CATALOGUE price. The GL is built from what the stock was LOADED
    // at, so the two answered the same question differently the moment a catalogue price moved —
    // and after the cost-basis fix the ledger is the correct one, which makes the screen the lie.
    //
    // Stock worth 1,000, catalogue says 3,000. An operator reconciling Inventory would be chasing
    // a 2,000 difference that does not exist.
    $this->svc->receive($this->store, $this->item, 10, 100);
    $this->item->update(['unit_cost' => 300]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $row = InventoryItemResource::getEloquentQuery()->whereKey($this->item->id)->first();

    expect(round((float) $row->stock_value, 2))->toBe(1000.0)
        ->and(round((float) $row->stock_value, 2))->toBe(inventoryBalance($this->asset->id));

    Filament::setTenant(null, isQuiet: true);
});

it('carries the same figure into the CSV register, so the export cannot disagree either', function () {
    $this->svc->receive($this->store, $this->item, 10, 100);
    $this->item->update(['unit_cost' => 300]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $csv = InventoryItemResource::stockRegisterCsv();
    $total = (float) end($csv['rows'])[6];

    expect(round($total, 2))->toBe(1000.0);

    Filament::setTenant(null, isQuiet: true);
});

it('keeps the register value at zero once the stock is fully issued', function () {
    // The control that catches a value column which merely ignores the catalogue: it must follow
    // the stock out of the door too.
    $this->svc->receive($this->store, $this->item, 10, 100);
    consumeStock($this->store, $this->item, 10);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $row = InventoryItemResource::getEloquentQuery()->whereKey($this->item->id)->first();

    expect(round((float) $row->stock_value, 2))->toBe(0.0);

    Filament::setTenant(null, isQuiet: true);
});
