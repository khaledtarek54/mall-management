<?php

use App\Filament\Admin\RelationManagers\MaintenanceChecklistRelationManager;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages\EditMaintenanceWorkOrder;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages\ListMaintenanceWorkOrders;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

function makeWorkOrder(int $assetId, array $attrs = []): MaintenanceWorkOrder
{
    return MaintenanceWorkOrder::create(array_merge([
        'asset_id' => $assetId, 'title' => 'Check HVAC', 'category' => 'hvac',
        'status' => 'open', 'scheduled_for' => now()->toDateString(),
    ], $attrs));
}

function checklistRM(MaintenanceWorkOrder $order)
{
    return Livewire::test(MaintenanceChecklistRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditMaintenanceWorkOrder::class,
    ]);
}

/* ---- RBAC + module ------------------------------------------------------- */

it('gates the work-order resource on preventive_maintenance permissions', function () {
    // operations owns preventive_maintenance.* — view + create; leasing none.
    $this->actingAs(makeUser('operations'));
    expect(MaintenanceWorkOrderResource::canViewAny())->toBeTrue();
    expect(MaintenanceWorkOrderResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(MaintenanceWorkOrderResource::canViewAny())->toBeFalse();

    $this->actingAs(makeUser('viewer'));
    expect(MaintenanceWorkOrderResource::canViewAny())->toBeTrue();
    expect(MaintenanceWorkOrderResource::canCreate())->toBeFalse();
});

it('hides the module when disabled', function () {
    $this->actingAs(makeUser('super_admin'));
    expect(MaintenanceWorkOrderResource::canViewAny())->toBeTrue();

    $settings = app(\App\Settings\ModulesSettings::class);
    $settings->preventive_maintenance = false;
    $settings->save();

    expect(MaintenanceWorkOrderResource::canViewAny())->toBeFalse();
});

it('scopes work orders to the current property', function () {
    $assetA = makeAsset(['code' => 'WOA']);
    $assetB = makeAsset(['code' => 'WOB']);
    makeWorkOrder($assetA->id, ['title' => 'A job']);
    makeWorkOrder($assetB->id, ['title' => 'B job']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($assetA, function () {
        expect(scopedResourceQuery(MaintenanceWorkOrderResource::class)->pluck('title')->all())
            ->toContain('A job')->not->toContain('B job');
    });
});

/* ---- Status transitions -------------------------------------------------- */

it('completes a work order via the action (operations)', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id);
    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($order) {
        Livewire::test(ListMaintenanceWorkOrders::class)
            ->callTableAction('complete', $order)
            ->assertHasNoTableActionErrors();
    });

    expect($order->fresh()->status)->toBe('done');
    expect($order->fresh()->completed_at)->not->toBeNull();
});

it('cancels a work order via the action', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id);
    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($order) {
        Livewire::test(ListMaintenanceWorkOrders::class)
            ->callTableAction('cancel', $order)
            ->assertHasNoTableActionErrors();
    });

    expect($order->fresh()->status)->toBe('cancelled');
});

it('hides complete/cancel/edit for a terminal (done) work order', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id, ['status' => 'done', 'completed_at' => now()]);
    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($order) {
        Livewire::test(ListMaintenanceWorkOrders::class)
            ->assertTableActionHidden('complete', $order)
            ->assertTableActionHidden('cancel', $order)
            ->assertTableActionHidden('edit', $order);
    });
});

it('hides the complete action from a role without preventive_maintenance.complete', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id);
    // viewer has view but not complete.
    $this->actingAs(makeUser('viewer', [$asset->id]));

    asTenant($asset, function () use ($order) {
        Livewire::test(ListMaintenanceWorkOrders::class)
            ->assertTableActionHidden('complete', $order);
    });
});

/* ---- Checklist ----------------------------------------------------------- */

it('adds a checklist item via the relation manager (operations)', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id);
    $this->actingAs(makeUser('operations', [$asset->id]));

    checklistRM($order)
        ->callTableAction('add_item', data: ['label' => 'Inspect belts'])
        ->assertHasNoTableActionErrors();

    expect(MaintenanceWorkOrderItem::where('maintenance_work_order_id', $order->id)->count())->toBe(1);
});

it('freezes the checklist add action on a terminal work order', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id, ['status' => 'done', 'completed_at' => now()]);
    $this->actingAs(makeUser('operations', [$asset->id]));

    checklistRM($order)->assertTableActionHidden('add_item');
});
