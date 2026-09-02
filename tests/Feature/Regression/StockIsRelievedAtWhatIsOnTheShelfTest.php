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

it('answers what the LEDGER holds when a movement is back-dated', function () {
    // **The trap the first version of this fix fell into.** A relief row's `unit_cost` is decided at
    // RECORD time, is immutable afterwards (`ChangeImpact` refuses all three columns) and is the
    // only thing the GL posts from. So a date-ordered replay — the textbook moving average — computes
    // a cost that was never posted and can never be posted, and re-creates the very residual this
    // whole fix exists to remove.
    //
    // Keyed:  Jan receive 100 @ 10 · Mar issue 100 (at 10, and POSTED at 10) · Feb receive 100 @ 20.
    // A replay in date order says 15. The books say 2,000 for 100 units, which is 20.
    //
    // This case is the one that must carry a ledger assertion beside its figure, because the
    // difference between the two answers is exactly the difference between tying out and not.
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

    expect(round($this->svc->onHand($this->item, $this->store), 3))->toEqual(100.0)
        ->and(wacInventoryBalance())->toEqual(2000.0)
        // 2,000 over 100 units. Not the 15 a date-ordered replay would say.
        ->and($this->svc->weightedAverageCost($this->item, $this->store))->toEqual(20.0);

    // …and issuing them closes Inventory at zero rather than leaving 500 behind.
    issueStock(100);

    expect(wacInventoryBalance())->toEqual(0.0);
});

it('leaves no cent stranded when stock is issued one unit at a time', function () {
    // Per-step rounding against an unrounded running average leaves a permanent credit balance on
    // an asset account — the same failure in miniature. One division absorbs it.
    $this->svc->receive($this->store, $this->item, 2, 10);
    $this->svc->receive($this->store, $this->item, 1, 20);

    expect(wacInventoryBalance())->toEqual(40.0);

    issueStock(1);
    issueStock(1);
    issueStock(1);

    expect(round($this->svc->onHand($this->item, $this->store), 3))->toEqual(0.0)
        ->and(wacInventoryBalance())->toEqual(0.0);
});

it('is the Inventory account s own value per unit, on every shape in this file', function () {
    // The invariant stated as an invariant, rather than as six separate figures: whatever the
    // movements are, the cost this relieves at is the ledger balance over the stock on hand. That is
    // what makes it un-divergeable, and it is the property a future edit has to preserve.
    $this->svc->receive($this->store, $this->item, 100, 10);
    issueStock(60);
    $this->svc->receive($this->store, $this->item, 60, 20);
    $this->svc->adjust($this->store, $this->item, 20, ['unit_cost' => 35]);
    issueStock(15);

    $onHand = round($this->svc->onHand($this->item, $this->store), 3);

    expect($onHand)->toBeGreaterThan(0.0)
        ->and($this->svc->weightedAverageCost($this->item, $this->store))
        ->toEqual(round(wacInventoryBalance() / $onHand, 2));
});

it('relieves at the average from the TENANT-REQUEST consumption door too', function () {
    // The door the invariant's own docblock claimed was already covered and was not: this relation
    // manager passed the item's CURRENT standard cost, which is the exact hole
    // `InventoryCostBasisDrift` closed on every other path. Measured before the fix: 100 @ 10 then
    // 100 @ 30 (Inventory 4,000), issue 100 at the catalogue's 10 → 3,000 standing for 100 units
    // really worth 2,000, from one click.
    //
    // Asserted through the SERVICE with no cost stated, which is what the relation manager now does
    // — the relation manager itself is a mounted Livewire component with an owner record, and what
    // can be wrong here is the valuation, not the form.
    $this->svc->receive($this->store, $this->item, 100, 10);
    $this->svc->receive($this->store, $this->item, 100, 30);

    // The catalogue still says 10 — an operator edited it, which is what the field is for.
    expect((float) $this->item->fresh()->unit_cost)->toEqual(10.0)
        ->and(wacInventoryBalance())->toEqual(4000.0);

    issueStock(100);

    expect(round($this->svc->onHand($this->item, $this->store), 3))->toEqual(100.0)
        // 100 units at the average of 20, not 100 at the catalogue's 10.
        ->and(wacInventoryBalance())->toEqual(2000.0);
});
