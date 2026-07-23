<?php

use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Admin\Resources\InventoryItems\Pages\EditInventoryItem;
use App\Filament\Admin\Resources\Warehouses\Pages\EditWarehouse;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/*
 * These three fields (inventory unit, warehouse category, fixed-asset category) used to be
 * TextInput->datalist(...) — a <datalist> is only a browser autocomplete hint, not a real
 * dropdown (it won't reliably open on click), so operators reported it as "not working".
 * They are now real Select dropdowns. A Select validates `in:options`, so a value already
 * stored that is NOT one of the built-in suggestions must still be an option — otherwise a
 * plain re-save on the edit page would blank or reject it. Each form merges existing DB
 * values into its option list to guarantee this; these tests lock that guarantee.
 */

it('keeps an out-of-list inventory unit selectable on edit (Select, not datalist)', function () {
    $asset = makeAsset();
    // 'pallet' is NOT one of the built-in suggestions (each/litre/kg/metre/box/roll).
    $item = InventoryItem::create([
        'sku' => 'SKU-PAL', 'name' => 'Bulk goods', 'unit' => 'pallet',
        'unit_cost' => 10, 'reorder_level' => 0, 'is_active' => true,
    ]);

    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($item) {
        Livewire::test(EditInventoryItem::class, ['record' => $item->getKey()])
            ->assertFormSet(['unit' => 'pallet']) // the stored value survives hydration
            ->call('save')
            ->assertHasNoFormErrors();             // and re-saves without an in:options error
    });

    expect($item->fresh()->unit)->toBe('pallet');
});

it('lets an operator create a brand-new inventory unit from the dropdown (free-form preserved)', function () {
    // The datalist allowed typing any unit; the Select must not regress that — a new unit
    // is added via the "create option" affordance and saved onto the record.
    $asset = makeAsset();
    $item = InventoryItem::create([
        'sku' => 'SKU-CREATE', 'name' => 'Odd goods', 'unit' => 'each',
        'unit_cost' => 10, 'reorder_level' => 0, 'is_active' => true,
    ]);

    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($item) {
        Livewire::test(EditInventoryItem::class, ['record' => $item->getKey()])
            ->mountFormComponentAction('unit', 'createOption')
            ->setFormComponentActionData(['value' => 'drum'])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['unit' => 'drum'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($item->fresh()->unit)->toBe('drum');
});

it('keeps an out-of-list warehouse category selectable on edit (Select, not datalist)', function () {
    $asset = makeAsset();
    // 'chemicals' is NOT a built-in suggestion (spare_parts/machines/consumables).
    $warehouse = Warehouse::create([
        'asset_id' => $asset->id, 'name' => 'Store', 'code' => 'WH-CHEM',
        'category' => 'chemicals', 'is_active' => true,
    ]);

    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($warehouse) {
        Livewire::test(EditWarehouse::class, ['record' => $warehouse->getKey()])
            ->assertFormSet(['category' => 'chemicals'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($warehouse->fresh()->category)->toBe('chemicals');
});

it('lets an operator create a brand-new warehouse category from the dropdown (free-form preserved)', function () {
    // The datalist allowed typing any value; the Select must not regress that — a new
    // category is added via the "create option" affordance and saved onto the record.
    $asset = makeAsset();
    $warehouse = Warehouse::create([
        'asset_id' => $asset->id, 'name' => 'Store', 'code' => 'WH-NEW',
        'category' => 'spare_parts', 'is_active' => true,
    ]);

    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($warehouse) {
        Livewire::test(EditWarehouse::class, ['record' => $warehouse->getKey()])
            ->mountFormComponentAction('category', 'createOption')
            ->setFormComponentActionData(['value' => 'landscaping'])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['category' => 'landscaping'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($warehouse->fresh()->category)->toBe('landscaping');
});

it('keeps an out-of-list fixed-asset category selectable on edit (Select, not datalist)', function () {
    $asset = makeAsset();
    // 'signage' is NOT a built-in suggestion (furniture/equipment/HVAC/IT/vehicles/fit-out).
    $fa = FixedAsset::create([
        'asset_id' => $asset->id, 'name' => 'Mall pylon sign', 'tag' => 'FA-SIGN',
        'category' => 'signage', 'acquisition_date' => '2026-01-01', 'acquisition_cost' => 5000,
        'salvage_value' => 0, 'useful_life_months' => 60, 'method' => 'straight_line',
        'funded_from' => 'cash',
    ]);

    $this->actingAs(makeUser('accounting', [$asset->id]));

    asTenant($asset, function () use ($fa) {
        Livewire::test(EditFixedAsset::class, ['record' => $fa->getKey()])
            ->assertFormSet(['category' => 'signage'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($fa->fresh()->category)->toBe('signage');
});
