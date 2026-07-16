<?php

use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\LowStockAlert;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Notifications\LowStockNotification;
use App\Services\StockMovementService;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * FR-INV-03 — "minimum-stock thresholds and low-stock alerts".
 *
 * The FRD hedges this one ("recommended addition — confirm with client if desired"), so it sits
 * behind the inventory module flag and only ever notifies. What it must never do is inherit the
 * portfolio-wide on-hand bug: an alert that stays quiet about the mall that is out, because another
 * mall holds a pile, is worse than no alert.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Notification::fake();

    $this->mallA = makeAsset(['code' => 'AAA']);
    $this->mallB = makeAsset(['code' => 'BBB']);
    $this->whA = Warehouse::create(['asset_id' => $this->mallA->id, 'name' => 'A store', 'code' => 'WA']);
    $this->whB = Warehouse::create(['asset_id' => $this->mallB->id, 'name' => 'B store', 'code' => 'WB']);
    $this->item = InventoryItem::create(['sku' => 'FILT', 'name' => 'Filter', 'unit' => 'each', 'unit_cost' => 50, 'reorder_level' => 10]);
    $this->storeman = makeUser('operations', [$this->mallA->id]);

    // Both malls start well stocked. A mall holding NONE of a tracked item is genuinely short, so
    // without this every assertion about mall A would also be counting mall B's real shortage.
    app(StockMovementService::class)->record(['warehouse_id' => $this->whA->id, 'inventory_item_id' => $this->item->id, 'type' => 'receipt', 'quantity' => 100, 'unit_cost' => 50]);
    app(StockMovementService::class)->record(['warehouse_id' => $this->whB->id, 'inventory_item_id' => $this->item->id, 'type' => 'receipt', 'quantity' => 100, 'unit_cost' => 50]);
});

/** Drive a warehouse down to an exact on-hand figure. */
function drawDownTo(Warehouse $wh, float $target): void
{
    $current = (float) StockMovement::where('inventory_item_id', test()->item->id)
        ->where('warehouse_id', $wh->id)->sum('quantity');

    if ($current > $target) {
        app(StockMovementService::class)->record([
            'warehouse_id' => $wh->id, 'inventory_item_id' => test()->item->id,
            'type' => 'consumption', 'quantity' => $current - $target, 'unit_cost' => 50,
        ]);
    }
}

function put(Warehouse $wh, float $qty, string $type = 'receipt'): void
{
    app(StockMovementService::class)->record([
        'warehouse_id' => $wh->id, 'inventory_item_id' => test()->item->id,
        'type' => $type, 'quantity' => $qty, 'unit_cost' => 50,
    ]);
}

function scan(): void
{
    test()->artisan('inventory:scan-low-stock')->assertExitCode(0);
}

/* ---- the point: per mall, not portfolio-wide ---------------------------- */

it('alerts the mall that is out even when another mall has plenty', function () {
    // THE regression this whole phase exists for. Portfolio-wide, this reads 100 and says nothing.
    drawDownTo($this->whA, 2); // mall A is short (2 <= 10); mall B still has 100

    scan();

    $alerts = LowStockAlert::all();
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->asset_id)->toBe($this->mallA->id);
    expect((float) $alerts->first()->on_hand)->toBe(2.0);
    Notification::assertSentTo($this->storeman, LowStockNotification::class);
});

it('does not alert a mall that has enough', function () {
    // Both malls hold 100 against a level of 10.
    scan();

    expect(LowStockAlert::count())->toBe(0);
    Notification::assertNothingSent();
});

it('alerts each short mall separately', function () {
    drawDownTo($this->whA, 2);
    drawDownTo($this->whB, 1);

    scan();

    expect(LowStockAlert::open()->pluck('asset_id')->sort()->values()->all())
        ->toBe(collect([$this->mallA->id, $this->mallB->id])->sort()->values()->all());
});

it('treats a mall holding none of the item as short', function () {
    // A mall that has never stocked it at all is, by definition, in need of some.
    $newMall = makeAsset(['code' => 'NEW']);
    Warehouse::create(['asset_id' => $newMall->id, 'name' => 'New store', 'code' => 'WN']);

    scan();

    $alert = LowStockAlert::open()->where('asset_id', $newMall->id)->sole();
    expect((float) $alert->on_hand)->toBe(0.0);
});

/* ---- idempotency: once per shortage, not once per run ------------------- */

it('alerts once and then stays quiet while nobody fixes it', function () {
    drawDownTo($this->whA, 2);

    scan();
    scan();
    scan();

    expect(LowStockAlert::count())->toBe(1);
    Notification::assertSentToTimes($this->storeman, LowStockNotification::class, 1);
});

it('resolves the alert when the stock comes back', function () {
    drawDownTo($this->whA, 2);
    scan();
    expect(LowStockAlert::open()->count())->toBe(1);

    put($this->whA, 50); // restocked
    scan();

    $alert = LowStockAlert::sole();
    expect($alert->isOpen())->toBeFalse();
    expect($alert->resolved_at)->not->toBeNull();
});

it('alerts again after a restock and a second dip', function () {
    // A new shortage is a new thing to say. Reusing the row must not silence it forever.
    drawDownTo($this->whA, 2);
    scan();

    put($this->whA, 50); // restocked to 52
    scan(); // resolves

    drawDownTo($this->whA, 7); // dips again
    scan();

    expect(LowStockAlert::count())->toBe(1); // still one row per (item, property)
    expect(LowStockAlert::sole()->isOpen())->toBeTrue();
    Notification::assertSentToTimes($this->storeman, LowStockNotification::class, 2);
});

/* ---- who hears about it ------------------------------------------------- */

it('tells only the short mall\'s staff', function () {
    $otherMallStaff = makeUser('operations', [$this->mallB->id]);
    drawDownTo($this->whA, 2); // only mall A is short

    scan();

    Notification::assertSentTo($this->storeman, LowStockNotification::class);
    Notification::assertNotSentTo($otherMallStaff, LowStockNotification::class);
});

/* ---- the guards that stop it becoming noise ----------------------------- */

it('ignores items with no reorder level set', function () {
    // 0 means "we do not track a minimum for this", not "alert whenever it hits zero" — otherwise
    // every item every mall has never stocked would alert forever.
    $untracked = InventoryItem::create(['sku' => 'X', 'name' => 'Untracked', 'unit' => 'each', 'unit_cost' => 1, 'reorder_level' => 0]);

    scan();

    expect(LowStockAlert::where('inventory_item_id', $untracked->id)->count())->toBe(0);
});

it('ignores an inactive item', function () {
    $this->item->update(['is_active' => false]);
    drawDownTo($this->whA, 0.5);

    scan();

    expect(LowStockAlert::count())->toBe(0);
});

it('says nothing about a property with no warehouse', function () {
    $noStore = makeAsset(['code' => 'NOS']); // no Warehouse at all

    scan();

    expect(LowStockAlert::where('asset_id', $noStore->id)->count())->toBe(0);
});

it('never alerts against the All-Properties pseudo-asset, even if it somehow owns a warehouse', function () {
    // Normally it owns none, so the no-warehouse guard already skips it — which means asserting
    // "no alert" on the default state proves nothing about the exclusion (mutation testing showed
    // exactly that: deleting the exclusion broke no test). The case the exclusion actually defends
    // is a MISCONFIGURED warehouse hung off the pseudo-asset, so that is what this builds.
    //
    // It must stay silent: 'ALL' is not a place, so "is ALL short of filters?" is the
    // portfolio-wide question this whole scan exists to avoid asking.
    $all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->sole();
    $strayWarehouse = Warehouse::create(['asset_id' => $all->id, 'name' => 'Stray', 'code' => 'WALL']);
    drawDownTo($this->whA, 2);

    scan();

    expect(LowStockAlert::where('asset_id', $all->id)->count())->toBe(0);
    // …and the real mall's shortage is still reported.
    expect(LowStockAlert::open()->where('asset_id', $this->mallA->id)->count())->toBe(1);
});

it('writes nothing on a dry run', function () {
    drawDownTo($this->whA, 2);

    $this->artisan('inventory:scan-low-stock --dry-run')->assertExitCode(0);

    expect(LowStockAlert::count())->toBe(0);
    Notification::assertNothingSent();
});

it('does nothing when the inventory module is off', function () {
    drawDownTo($this->whA, 2);
    app(ModulesSettings::class)->fill(['inventory' => false])->save();

    scan();

    expect(LowStockAlert::count())->toBe(0);
});

it('fires exactly at the reorder level, not just below it', function () {
    // "minimum-stock threshold" — at the minimum you are already meant to reorder.
    drawDownTo($this->whA, 10); // exactly the level

    scan();

    expect(LowStockAlert::open()->where('asset_id', $this->mallA->id)->count())->toBe(1);
});
