<?php

use App\Filament\Admin\Resources\InventoryItems\Pages\CreateInventoryItem;
use App\Filament\Admin\Resources\InventoryItems\Pages\EditInventoryItem;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * SW-194 — an operator can state how much of an item we buy at a time.
 *
 * `reorder_level` says WHEN to buy. `reorder_quantity` says HOW MUCH, and has since the 2026-08-19
 * migration that added it — read by `DraftReorderPurchaseService`, cast on the model, fillable, and
 * settable on NO screen and NO importer. Measured at HEAD before this fix: the column appears in
 * `app/` exactly twice, both inside that service's `$item->reorder_quantity !== null` ternary. The
 * branch was reachable only from a factory or a test, so on a real install every drafted purchase
 * line carried the SHORTFALL — the number that lands the item exactly on its own threshold and
 * alerts again on the next scan, which is precisely what the column was added to end.
 *
 * `LowStockDraftsAPurchaseTest` proves the service's arithmetic by writing the column directly.
 * That is a fixture writing a value no screen can write — green over dead code — so this file
 * drives the real form instead, and follows the typed figure through to the drafted line.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    ensureAllPropertiesAsset();

    $this->mall = makeAsset(['code' => 'SW194']);
    $this->warehouse = Warehouse::create([
        'asset_id' => $this->mall->id, 'code' => 'SW194-WH', 'name' => 'Main store',
    ]);

    $this->actingAs(makeUser('operations', [$this->mall->id]));
});

function sw194CreateItem(array $overrides = []): void
{
    Livewire::test(CreateInventoryItem::class)
        ->fillForm(array_merge([
            'sku' => 'SW194-FILTER',
            'name' => 'Air filter',
            'unit' => 'each',
            'unit_cost' => 100,
            'reorder_level' => 10,
            'reorder_quantity' => 50,
            'is_active' => true,
        ], $overrides))
        ->call('create')
        ->assertHasNoFormErrors();
}

it('records the reorder quantity typed on the item form', function () {
    asTenant($this->mall, fn () => sw194CreateItem());

    expect((float) InventoryItem::where('sku', 'SW194-FILTER')->sole()->reorder_quantity)->toBe(50.0);
});

it('keeps a blank reorder quantity NULL, because "we have not said" is not zero', function () {
    // THE CONTROL that stops the obvious wrong fix. Coercing a blank to 0 — the way the model
    // deliberately does for `reorder_level` — would make the field always "stated", and 0 means
    // `DraftReorderPurchaseService` skips the line entirely (`$quantity <= 0`): the alert fires and
    // no line is drafted, which is worse than the shortfall it replaced.
    asTenant($this->mall, fn () => sw194CreateItem(['reorder_quantity' => null]));

    expect(InventoryItem::where('sku', 'SW194-FILTER')->sole()->reorder_quantity)->toBeNull();
});

it('keeps a stated reorder quantity through an unrelated edit', function () {
    asTenant($this->mall, function () {
        sw194CreateItem();

        Livewire::test(EditInventoryItem::class, ['record' => InventoryItem::where('sku', 'SW194-FILTER')->sole()->getRouteKey()])
            ->fillForm(['name' => 'Air filter (G4)'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    // The field is on the SHARED schema, so Create and Edit cannot diverge — a Create-only field
    // would be dehydrated to null here and silently erase the figure on the next name change.
    expect((float) InventoryItem::where('sku', 'SW194-FILTER')->sole()->reorder_quantity)->toBe(50.0)
        ->and(InventoryItem::where('sku', 'SW194-FILTER')->sole()->name)->toBe('Air filter (G4)');
});

it('drafts the stated quantity rather than the shortfall', function () {
    asTenant($this->mall, fn () => sw194CreateItem());

    $item = InventoryItem::where('sku', 'SW194-FILTER')->sole();

    // On hand 2 against a reorder level of 10 — a shortfall of 8.
    StockMovement::create([
        'inventory_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'receipt', 'quantity' => 2, 'unit_cost' => 100, 'moved_on' => now(),
    ]);

    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    $line = PurchaseRequest::where('status', PurchaseRequest::STATUS_DRAFT)->sole()->lines()->sole();

    // 50, not 8 — the branch that was unreachable from any screen until the field existed.
    expect((float) $line->quantity)->toBe(50.0);
});
