<?php

use App\Filament\Admin\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Models\InventoryItem;
use App\Models\LowStockAlert;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * **A `reorder_level` of 0 means "we do not track a minimum", and all three readers must say so**
 * (SW-195, 2026-09-04).
 *
 * `ScanLowStockCommand` has said it in writing since it shipped — *"0 means we do not track a
 * minimum for this, not alert whenever it hits zero — otherwise every item a mall has never
 * stocked would alert forever"* — and it filtered `reorder_level > 0`. The LIST answered the
 * opposite question about the same items, twice: the on-hand column coloured `0 <= 0` DANGER, and
 * the low-stock filter's `reorder_level >= sum(quantity)` was TRUE. So the reorder worklist opened
 * on every catalogue item the mall had never stocked, each painted red, and none of them would
 * ever produce an alert — the screen and the bell disagreeing about the same shortage.
 *
 * `InventoryItemForm` defaults `reorder_level` to 0, so that is not an exotic row: it is every
 * item created through the panel by somebody who had no threshold to type.
 *
 * The fix is one predicate on the model (`isLowAt()` / the `tracksAReorderLevel()` scope) read by
 * all three, so each test below has a CONTROL beside it — a genuinely short item that must still
 * be in the worklist, still red, and still alerted about. A fix that simply stopped calling
 * anything low would satisfy the refusals alone.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Notification::fake();

    $this->mall = makeAsset(['code' => 'RLV']);
    $this->store = Warehouse::create([
        'asset_id' => $this->mall->id,
        'name' => 'Parts store',
        'code' => 'RLV-1',
        'is_active' => true,
    ]);

    // What the create form produces when nobody types a threshold, and nothing has been received
    // yet: reorder_level 0, on hand 0.
    $this->untracked = InventoryItem::create([
        'sku' => 'UNTRACKED', 'name' => 'Door handle', 'unit' => 'each',
        'unit_cost' => 40, 'reorder_level' => 0, 'is_active' => true,
    ]);

    // The control: a stated minimum of 10 with 2 on the shelf — genuinely short.
    $this->short = InventoryItem::create([
        'sku' => 'SHORT', 'name' => 'HVAC filter', 'unit' => 'each',
        'unit_cost' => 50, 'reorder_level' => 10, 'is_active' => true,
    ]);

    StockMovement::create([
        'warehouse_id' => $this->store->id,
        'inventory_item_id' => $this->short->id,
        'type' => 'receipt', 'quantity' => 2, 'unit_cost' => 50,
        'moved_on' => '2026-01-05',
    ]);

    $this->actingAs(makeUser('super_admin', [$this->mall->id]));
});

it('keeps an untracked item out of the reorder worklist and the short one in it', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListInventoryItems::class)
            ->filterTable('low_stock')
            ->assertCanSeeTableRecords([$this->short])
            ->assertCanNotSeeTableRecords([$this->untracked])
            ->assertCountTableRecords(1);
    });
});

it('does not paint an untracked item red, and still paints the short one red', function () {
    asTenant($this->mall, function () {
        $column = Livewire::test(ListInventoryItems::class)->instance()->getTable()->getColumn('on_hand');

        expect($column->record($this->untracked)->getColor(0.0))->toBe('success')
            ->and($column->record($this->short)->getColor(2.0))->toBe('danger');
    });
});

it('never alerts about an untracked item and does alert about the short one', function () {
    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    expect(LowStockAlert::where('inventory_item_id', $this->untracked->id)->count())->toBe(0)
        ->and(LowStockAlert::where('inventory_item_id', $this->short->id)->count())->toBe(1);
});

it('answers the same question in PHP and in SQL', function () {
    // The two spellings cannot be collapsed — the filter compares against a correlated subquery —
    // so the only thing keeping them together is this assertion.
    expect($this->untracked->tracksAReorderLevel())->toBeFalse()
        ->and($this->untracked->isLowAt(0.0))->toBeFalse()
        ->and($this->short->tracksAReorderLevel())->toBeTrue()
        ->and($this->short->isLowAt(2.0))->toBeTrue()
        ->and($this->short->isLowAt(11.0))->toBeFalse()
        ->and(InventoryItem::query()->tracksAReorderLevel()->pluck('id')->all())
        ->toBe([$this->short->id]);
});
