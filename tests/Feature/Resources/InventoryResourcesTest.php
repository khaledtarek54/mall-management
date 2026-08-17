<?php

use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Admin\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\Admin\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/* ---- RBAC ---------------------------------------------------------------- */

it('gates the inventory resources on inventory permissions', function () {
    // operations has inventory.* — can view + create; leasing has none.
    $this->actingAs(makeUser('operations'));
    expect(WarehouseResource::canViewAny())->toBeTrue();
    expect(InventoryItemResource::canCreate())->toBeTrue();
    expect(StockMovementResource::canViewAny())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(WarehouseResource::canViewAny())->toBeFalse();
    expect(InventoryItemResource::canViewAny())->toBeFalse();

    // viewer sees, but cannot create.
    $this->actingAs(makeUser('viewer'));
    expect(WarehouseResource::canViewAny())->toBeTrue();
    expect(WarehouseResource::canCreate())->toBeFalse();
});

it('hides inventory resources when the module is disabled', function () {
    $this->actingAs(makeUser('super_admin'));
    expect(WarehouseResource::canViewAny())->toBeTrue();

    $settings = app(ModulesSettings::class);
    $settings->inventory = false;
    $settings->save();

    expect(WarehouseResource::canViewAny())->toBeFalse();
    expect(StockMovementResource::canViewAny())->toBeFalse();
});

/* ---- Rendering + property scoping ---------------------------------------- */

it('scopes warehouses + stock movements to the current property', function () {
    $assetA = makeAsset(['code' => 'IWA']);
    $assetB = makeAsset(['code' => 'IWB']);
    $wa = Warehouse::create(['asset_id' => $assetA->id, 'name' => 'A Store', 'code' => 'A1']);
    $wb = Warehouse::create(['asset_id' => $assetB->id, 'name' => 'B Store', 'code' => 'B1']);
    $item = InventoryItem::create(['sku' => 'SKU-SC', 'name' => 'Filter', 'unit' => 'each']);
    app(StockMovementService::class)->receive($wa, $item, 5, 1, ['reference' => 'RA']);
    app(StockMovementService::class)->receive($wb, $item, 5, 1, ['reference' => 'RB']);

    $this->actingAs(makeUser('super_admin'));

    // Warehouse (direct asset_id scoping) + StockMovement (via warehouse.asset_id).
    asTenant($assetA, function () {
        expect(scopedResourceQuery(WarehouseResource::class)->pluck('code')->all())
            ->toContain('A1')->not->toContain('B1');
        expect(scopedResourceQuery(StockMovementResource::class)->pluck('reference')->all())
            ->toContain('RA')->not->toContain('RB');
    });
});

it('shows a derived on-hand quantity on the items list', function () {
    $this->actingAs(makeUser('super_admin'));
    $asset = makeAsset();
    $w = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'SKU-1', 'name' => 'Filter', 'unit' => 'each', 'unit_cost' => 10, 'reorder_level' => 5]);
    app(StockMovementService::class)->receive($w, $item, 30, 10);

    asTenant($asset, function () use ($item) {
        Livewire::test(ListInventoryItems::class)
            ->assertCanSeeTableRecords([$item])
            ->assertSee('30'); // derived on-hand
    });
});

/* ---- Receive / Adjust actions ------------------------------------------- */

it('receives stock via the action (creates a receipt movement)', function () {
    $asset = makeAsset();
    $w = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'SKU-2', 'name' => 'Pump Seal', 'unit' => 'each', 'unit_cost' => 25]);

    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($w, $item) {
        Livewire::test(ListStockMovements::class)
            ->callAction('receive', data: [
                'warehouse_id' => $w->id,
                'inventory_item_id' => $item->id,
                'quantity' => 40,
                'unit_cost' => 25,
                'moved_on' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();
    });

    $movement = StockMovement::first();
    expect($movement)->not->toBeNull();
    expect($movement->type)->toBe('receipt');
    expect((float) $movement->quantity)->toBe(40.0);
    expect(app(StockMovementService::class)->onHand($item, $w))->toBe(40.0);
});

it('refuses to receive stock into a warehouse in a non-visible property (tampering)', function () {
    $assetA = makeAsset(['code' => 'TPA']);
    $assetB = makeAsset(['code' => 'TPB']);
    $warehouseB = Warehouse::create(['asset_id' => $assetB->id, 'name' => 'B Store', 'code' => 'TB1']);
    $item = InventoryItem::create(['sku' => 'SKU-TAMPER', 'name' => 'Item', 'unit' => 'each']);

    // Operations user restricted to property A tries to receive into B's warehouse.
    $this->actingAs(makeUser('operations', [$assetA->id]));

    asTenant($assetA, function () use ($warehouseB, $item) {
        try {
            Livewire::test(ListStockMovements::class)
                ->callAction('receive', data: [
                    'warehouse_id' => $warehouseB->id, // tampered — outside A
                    'inventory_item_id' => $item->id,
                    'quantity' => 10,
                    'unit_cost' => 1,
                    'moved_on' => now()->toDateString(),
                ]);
        } catch (Throwable $e) {
            // abort(403) may surface as an exception depending on the Livewire path.
        }
    });

    // No stock moved into property B.
    expect(StockMovement::where('warehouse_id', $warehouseB->id)->count())->toBe(0);
});

it('adjusts stock down via the action (signed negative)', function () {
    $asset = makeAsset();
    $w = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'SKU-3', 'name' => 'Bolt', 'unit' => 'each', 'unit_cost' => 2]);
    app(StockMovementService::class)->receive($w, $item, 100, 2);

    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($w, $item) {
        Livewire::test(ListStockMovements::class)
            ->callAction('adjust', data: [
                'warehouse_id' => $w->id,
                'inventory_item_id' => $item->id,
                'quantity' => -8, // shrinkage
                'moved_on' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();
    });

    expect(app(StockMovementService::class)->onHand($item, $w))->toBe(92.0);
});
