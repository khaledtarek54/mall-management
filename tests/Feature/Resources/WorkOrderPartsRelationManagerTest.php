<?php

use App\Filament\Admin\RelationManagers\WorkOrderPartsRelationManager;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages\EditMaintenanceWorkOrder;
use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderPart;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Services\WorkOrderPartService;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * The parts UI (FR-CM-09/10/11). The service is covered in the scenario suite; what this
 * pins is the surface an engineer actually touches — that the actions exist, that they are
 * gated the same way the service is, and that the table renders **with rows** (an empty
 * table would hide every `$state`-closure bug in it).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'RMP']);
    $this->warehouse = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Main', 'code' => 'W1']);
    $this->item = InventoryItem::create(['sku' => 'PMP-SEAL', 'name' => 'Pump seal', 'unit' => 'each', 'unit_cost' => 100]);
    app(StockMovementService::class)->record([
        'warehouse_id' => $this->warehouse->id, 'inventory_item_id' => $this->item->id,
        'type' => 'receipt', 'quantity' => 500, 'unit_cost' => 100,
    ]);
    $this->order = MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'description' => 'Pump leaking', 'title' => 'Fix pump', 'category' => 'plumbing',
        'scheduled_for' => '2026-07-01',
    ]);
});

function partsRM(MaintenanceWorkOrder $order)
{
    return Livewire::test(WorkOrderPartsRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditMaintenanceWorkOrder::class,
    ]);
}

function requestPart(float $qty = 2): MaintenanceWorkOrderPart
{
    return app(WorkOrderPartService::class)->requestInternal(test()->order, [
        'inventory_item_id' => test()->item->id, 'warehouse_id' => test()->warehouse->id, 'quantity' => $qty,
    ], makeUser('operations', [test()->asset->id])->id);
}

it('renders both sources and every status with rows', function () {
    // Rows of each shape: the label/description/badge closures differ per source and per
    // status, so a one-row table proves almost nothing.
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    $svc = app(WorkOrderPartService::class);

    requestPart(2);
    $svc->reject(requestPart(3), 'Use the refurbished one.', makeUser('manager', [$this->asset->id]));
    $svc->approve(requestPart(4), makeUser('manager', [$this->asset->id]));
    $svc->recordExternal($this->order, [
        'description' => 'Bespoke gasket', 'quantity' => 1, 'unit_cost' => 750, 'reference' => 'INV-9',
    ], makeUser('operations', [$this->asset->id])->id);

    partsRM($this->order)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(MaintenanceWorkOrderPart::where('maintenance_work_order_id', $this->order->id)->get())
        ->assertSee('Pump seal')
        ->assertSee('Bespoke gasket');
});

it('requests an internal part without moving stock', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));
    $before = (float) StockMovement::where('inventory_item_id', $this->item->id)->sum('quantity');

    partsRM($this->order)
        ->callTableAction('request_internal', data: [
            'warehouse_id' => $this->warehouse->id, 'inventory_item_id' => $this->item->id, 'quantity' => 2,
        ])
        ->assertHasNoTableActionErrors();

    $part = MaintenanceWorkOrderPart::where('maintenance_work_order_id', $this->order->id)->sole();
    expect($part->status)->toBe(MaintenanceWorkOrderPart::STATUS_PENDING);
    expect((float) StockMovement::where('inventory_item_id', $this->item->id)->sum('quantity'))->toBe($before);
});

it('records an external purchase with no approval', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    partsRM($this->order)
        ->callTableAction('record_external', data: [
            'description' => 'Bespoke gasket', 'quantity' => 1, 'unit_cost' => 750,
        ])
        ->assertHasNoTableActionErrors();

    expect(MaintenanceWorkOrderPart::where('maintenance_work_order_id', $this->order->id)->sole()->status)
        ->toBe(MaintenanceWorkOrderPart::STATUS_RECORDED);
});

it('approves through the table action and moves the stock', function () {
    $part = requestPart(2);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    $before = (float) StockMovement::where('inventory_item_id', $this->item->id)->sum('quantity');

    partsRM($this->order)->callTableAction('approve', $part)->assertHasNoTableActionErrors();

    expect($part->fresh()->status)->toBe(MaintenanceWorkOrderPart::STATUS_APPROVED);
    expect((float) StockMovement::where('inventory_item_id', $this->item->id)->sum('quantity'))->toBe($before - 2);
});

/* ---- the UI is gated the same way the service is ------------------------- */

it('offers a viewer no way to request or decide a part', function () {
    $part = requestPart(2);
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    partsRM($this->order)
        ->assertSuccessful() // read-only visibility is fine…
        ->assertTableActionHidden('request_internal')
        ->assertTableActionHidden('record_external')
        ->assertTableActionHidden('approve', $part) // …acting on it is not
        ->assertTableActionHidden('reject', $part);
});

it('hides the decision from a viewer even with the ladder deleted', function () {
    // Mirrors the service regression: ApprovalPolicy waves everyone through when no bands
    // exist, so the UI must check the base inventory right independently of it.
    $part = requestPart(2);
    ApprovalRule::query()->delete();
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    partsRM($this->order)
        ->assertTableActionHidden('approve', $part)
        ->assertTableActionHidden('reject', $part);
});

it('hides approval from a supervisor whose tier does not cover the value', function () {
    $part = requestPart(60); // 6,000 → tier_2
    $this->actingAs(makeUser('operations', [$this->asset->id])); // tier_1 only

    partsRM($this->order)->assertTableActionHidden('approve', $part);
});

it('hides approval from the requester themselves', function () {
    $engineer = makeUser('operations', [$this->asset->id]);
    $part = app(WorkOrderPartService::class)->requestInternal($this->order, [
        'inventory_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2,
    ], $engineer->id);

    $this->actingAs($engineer);
    partsRM($this->order)->assertTableActionHidden('approve', $part);
});

it('offers no way to add parts to a closed work order', function () {
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    $this->order->update(['status' => 'cancelled']);

    partsRM($this->order->fresh())
        ->assertTableActionHidden('request_internal')
        ->assertTableActionHidden('record_external');
});

it('refuses a draw from another mall\'s warehouse', function () {
    // You cannot draw from another mall's shelf. Asserting the option is merely absent from
    // the dropdown would prove nothing about someone POSTing the id directly; what has to
    // hold is that the submitted value is rejected.
    $other = makeAsset(['code' => 'OTH']);
    $foreign = Warehouse::create(['asset_id' => $other->id, 'name' => 'Other mall store', 'code' => 'W9']);
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    partsRM($this->order)
        ->callTableAction('request_internal', data: [
            'warehouse_id' => $foreign->id, 'inventory_item_id' => $this->item->id, 'quantity' => 2,
        ])
        ->assertHasTableActionErrors(['warehouse_id']);

    expect(MaintenanceWorkOrderPart::where('maintenance_work_order_id', $this->order->id)->count())->toBe(0);
});
