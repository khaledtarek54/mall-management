<?php

use App\Filament\Admin\RelationManagers\StockConsumptionRelationManager;
use App\Filament\Admin\Resources\TenantRequests\Pages\EditTenantRequest;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\TenantRequest;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->request = makeTenantRequest(['unit_id' => $this->unit->id]);
    $this->warehouse = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Store', 'code' => 'S1']);
    $this->item = InventoryItem::create(['sku' => 'SKU-C', 'name' => 'Pump Seal', 'unit' => 'each', 'unit_cost' => 25]);
    app(StockMovementService::class)->receive($this->warehouse, $this->item, 50, 25);
});

function consumptionRM(TenantRequest $request)
{
    return Livewire::test(StockConsumptionRelationManager::class, [
        'ownerRecord' => $request,
        'pageClass' => EditTenantRequest::class,
    ]);
}

it('logs consumed materials against a ticket (decrements stock, links source, costs at standard)', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    consumptionRM($this->request)
        ->callTableAction('consume', data: [
            'warehouse_id' => $this->warehouse->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 3,
            'moved_on' => now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors();

    $movement = StockMovement::where('type', 'consumption')->first();
    expect($movement)->not->toBeNull();
    expect((float) $movement->quantity)->toBe(-3.0);                 // stored negative
    expect($movement->source_type)->toBe($this->request->getMorphClass());
    expect($movement->source_id)->toBe($this->request->id);          // linked to the ticket
    expect((float) $movement->unit_cost)->toBe(25.0);                // item standard cost
    expect(app(StockMovementService::class)->onHand($this->item, $this->warehouse))->toBe(47.0);
    expect($this->request->stockConsumptions()->count())->toBe(1);
});

it('refuses consumption from a warehouse in a different property than the ticket (tampering)', function () {
    $otherAsset = makeAsset(['code' => 'OTH']);
    $otherWarehouse = Warehouse::create(['asset_id' => $otherAsset->id, 'name' => 'Other', 'code' => 'O1']);

    // User can see BOTH properties, but the ticket is for $this->asset — consumption
    // must come from the ticket's property, never another (even a visible one).
    $this->actingAs(makeUser('operations', [$this->asset->id, $otherAsset->id]));

    try {
        consumptionRM($this->request)->callTableAction('consume', data: [
            'warehouse_id' => $otherWarehouse->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'moved_on' => now()->toDateString(),
        ]);
    } catch (\Throwable $e) {
        // abort(403) may surface as an exception depending on the Livewire path.
    }

    expect(StockMovement::where('warehouse_id', $otherWarehouse->id)->count())->toBe(0);
});

it('refuses to log consumption against a terminal (closed) ticket', function () {
    $this->request->update(['status' => 'closed']); // terminal → not editable
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    try {
        consumptionRM($this->request->fresh())->callTableAction('consume', data: [
            'warehouse_id' => $this->warehouse->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'moved_on' => now()->toDateString(),
        ]);
    } catch (\Throwable $e) {
        // abort(403) may surface as an exception depending on the Livewire path.
    }

    expect(StockMovement::where('type', 'consumption')->count())->toBe(0);
});

it('hides the consumption panel when the inventory module is off', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));
    expect(StockConsumptionRelationManager::canViewForRecord($this->request, EditTenantRequest::class))->toBeTrue();

    $settings = app(\App\Settings\ModulesSettings::class);
    $settings->inventory = false;
    $settings->save();

    expect(StockConsumptionRelationManager::canViewForRecord($this->request, EditTenantRequest::class))->toBeFalse();
});
