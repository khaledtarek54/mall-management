<?php

use App\Models\InventoryItem;
use App\Models\JournalLine;
use App\Models\Warehouse;
use App\Services\Accounting\AccountResolver;
use App\Services\StockMovementService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * THE AVERAGE IS OF WHAT IS ON THE SHELF, NOT OF EVERY RECEIPT EVER MADE.
 *
 * `weightedAverageCost()` averaged the ADDS_STOCK movements alone — every receipt the item had ever
 * had, whether that stock is still there or was issued three years ago. So a price rise is diluted
 * by history that no longer exists:
 *
 *   receive 100 @ 10 → Dr Inventory 1,000 · issue all 100 → Cr 1,000 · on hand 0, Inventory 0
 *   receive 100 @ 20 → Dr Inventory 2,000 · the old average is (100×10 + 100×20) ÷ 200 = **15**
 *   issue those 100  → Cr 1,500 against a 2,000 debit
 *
 * On hand 0 and Inventory standing at **500** for stock that is gone — and it COMPOUNDS, because
 * the next receipt is diluted by both. Nothing re-derives a perpetual account, so the residual is
 * permanent, and the owner statements are drawn off these balances.
 *
 * It is the same hole the standard-cost fallback opened (`InventoryCostBasisDriftTest`), reached by
 * a different road: relieving stock at a figure that is not what it was loaded at. The perpetual
 * moving average replays the ledger in order — an ADD contributes its own quantity and value, a
 * REMOVE relieves at the average as it stood at that moment — so what survives is the value of the
 * stock that survived.
 *
 * Driven through the REAL sweep for the balance, because a journalizer's arithmetic proves only
 * itself.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->svc = app(StockMovementService::class);
    $this->asset = makeAsset(['code' => 'WAC']);
    $this->item = InventoryItem::create([
        'name' => 'Chiller filter', 'sku' => 'CF-2', 'unit' => 'pc',
        'unit_cost' => 10, 'reorder_level' => 0, 'is_active' => true,
    ]);
    $this->store = Warehouse::create([
        'asset_id' => $this->asset->id, 'name' => 'Main store', 'code' => 'WACMAIN',
        'category' => 'spare_parts', 'is_active' => true,
    ]);
});

/** Issue stock the way every real caller does: no unit_cost, letting the service value it. */
function issueStock(float $quantity): void
{
    app(StockMovementService::class)->record([
        'warehouse_id' => test()->store->id,
        'inventory_item_id' => test()->item->id,
        'type' => 'consumption',
        'quantity' => $quantity,
    ]);
}

/** The Inventory asset balance for this property, out of the POSTED ledger. */
function wacInventoryBalance(): float
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $id = app(AccountResolver::class)->id('inventory', test()->asset->id);
    $lines = JournalLine::where('ledger_account_id', $id);

    return round((float) $lines->sum('debit') - (float) (clone $lines)->sum('credit'), 2);
}

it('does not dilute a new price with stock that has already gone', function () {
    // A full cycle at 10, so nothing of it remains.
    $this->svc->receive($this->store, $this->item, 100, 10);
    issueStock(100);

    expect(wacInventoryBalance())->toEqual(0.0);   // the control: the first cycle is clean

    // A second cycle at twice the price.
    $this->svc->receive($this->store, $this->item, 100, 20);

    // The average is what is ON THE SHELF — 20 — not (100×10 + 100×20) ÷ 200 = 15.
    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(20.0);

    issueStock(100);

    expect(round($this->svc->onHand($this->item, $this->store), 3))->toEqual(0.0)
        // …and Inventory closes at zero rather than holding 500 for stock that does not exist.
        ->and(wacInventoryBalance())->toEqual(0.0);
});

it('averages the layers that are ACTUALLY on hand, not the ones that are gone', function () {
    // The control for the control: a genuine mixed shelf must still average. 50 @ 10 and 50 @ 20
    // sitting together is 15 — the same number the broken version produced above, arrived at
    // honestly, so a fix that simply took the LAST receipt's price would pass the case above and
    // fail here.
    $this->svc->receive($this->store, $this->item, 50, 10);
    $this->svc->receive($this->store, $this->item, 50, 20);

    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(15.0);

    issueStock(50);

    // Half relieved at 15 leaves 50 on hand still worth 15 each — a moving average does not
    // re-expose the layers underneath it.
    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(15.0)
        ->and(wacInventoryBalance())->toEqual(750.0);
});

it('relieves a PART issue at the average as it stood, then re-averages on the next receipt', function () {
    $this->svc->receive($this->store, $this->item, 100, 10);
    issueStock(60);                                    // 40 left, worth 400

    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(10.0);

    $this->svc->receive($this->store, $this->item, 60, 20);   // 100 on hand, worth 400 + 1,200

    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(16.0)
        ->and(wacInventoryBalance())->toEqual(1600.0);
});

it('counts a positive adjustment as a load and a negative one as a relief', function () {
    // An `adjustment` is in neither ADDS_STOCK nor REMOVES_STOCK — it is a SIGNED correction, so
    // the sign has to decide. Found stock carries a cost; a write-off relieves at the average.
    $this->svc->receive($this->store, $this->item, 100, 10);

    $this->svc->adjust($this->store, $this->item, 100, ['unit_cost' => 20]);

    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(15.0);

    $this->svc->adjust($this->store, $this->item, -100);

    // Written off at 15, leaving 100 still worth 15 — not re-exposing the 10s underneath.
    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(15.0)
        ->and(round($this->svc->onHand($this->item, $this->store), 3))->toEqual(100.0);
});

it('still falls back to the catalogue cost when the shelf is empty', function () {
    // Nothing has been received, so there is no loaded value to derive from — which is exactly what
    // the old behaviour always did, and must keep doing.
    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(10.0);
});

it('replays a BACK-DATED movement in its own place, not in the order it was keyed', function () {
    // `moved_on` is a date and a correction is keyed after the fact, so insertion order and the
    // order things actually happened are different sequences — and a moving average that follows
    // the wrong one gives a different answer, not a slightly wrong one.
    //
    // Keyed:  Jan receive 100 @ 10 · Mar issue 100 · Feb receive 100 @ 20 (entered last)
    // Happened: Jan @ 10 → Feb @ 20 (avg 15) → Mar issue 100 at 15, leaving 100 worth 15.
    //
    // Replayed in KEYING order the January stock is relieved by the March issue before February's
    // receipt exists, leaving 100 @ 20. Fifteen against twenty on the same six rows.
    $this->svc->receive($this->store, $this->item, 100, 10, ['moved_on' => '2026-01-10']);

    app(StockMovementService::class)->record([
        'warehouse_id' => $this->store->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'consumption',
        'quantity' => 100,
        'moved_on' => '2026-03-10',
    ]);

    // Entered last, dated in the middle — the receipt somebody forgot to key in February.
    $this->svc->receive($this->store, $this->item, 100, 20, ['moved_on' => '2026-02-10']);

    expect($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(15.0)
        ->and(round($this->svc->onHand($this->item, $this->store), 3))->toEqual(100.0);
});
