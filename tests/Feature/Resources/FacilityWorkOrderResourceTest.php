<?php

use App\Filament\Admin\RelationManagers\ServiceChecklistRelationManager;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\EditFacilityWorkOrder;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

function makeWorkOrder(int $assetId, array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => $assetId, 'title' => 'Check HVAC', 'trade_id' => tradeId('hvac'),
        'status' => 'open', 'scheduled_for' => now()->toDateString(),
    ], $attrs));
}

function checklistRM(FacilityWorkOrder $order)
{
    return Livewire::test(ServiceChecklistRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditFacilityWorkOrder::class,
    ]);
}

/* ---- RBAC + module ------------------------------------------------------- */

it('gates the work-order resource on facility permissions', function () {
    // operations owns facility.* — view + create; leasing none.
    $this->actingAs(makeUser('operations'));
    expect(FacilityWorkOrderResource::canViewAny())->toBeTrue();
    expect(FacilityWorkOrderResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(FacilityWorkOrderResource::canViewAny())->toBeFalse();

    $this->actingAs(makeUser('viewer'));
    expect(FacilityWorkOrderResource::canViewAny())->toBeTrue();
    expect(FacilityWorkOrderResource::canCreate())->toBeFalse();
});

it('hides the module when disabled', function () {
    $this->actingAs(makeUser('super_admin'));
    expect(FacilityWorkOrderResource::canViewAny())->toBeTrue();

    $settings = app(ModulesSettings::class);
    $settings->facility = false;
    $settings->save();

    expect(FacilityWorkOrderResource::canViewAny())->toBeFalse();
});

it('scopes work orders to the current property', function () {
    $assetA = makeAsset(['code' => 'WOA']);
    $assetB = makeAsset(['code' => 'WOB']);
    makeWorkOrder($assetA->id, ['title' => 'A job']);
    makeWorkOrder($assetB->id, ['title' => 'B job']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($assetA, function () {
        expect(scopedResourceQuery(FacilityWorkOrderResource::class)->pluck('title')->all())
            ->toContain('A job')->not->toContain('B job');
    });
});

/* ---- Status transitions -------------------------------------------------- */

it('completes a work order via the action (operations)', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id);
    $this->actingAs(makeUser('operations', [$asset->id]));

    asTenant($asset, function () use ($order) {
        Livewire::test(ListFacilityWorkOrders::class)
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
        Livewire::test(ListFacilityWorkOrders::class)
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
        Livewire::test(ListFacilityWorkOrders::class)
            ->assertTableActionHidden('complete', $order)
            ->assertTableActionHidden('cancel', $order)
            ->assertTableActionHidden('edit', $order);
    });
});

it('hides the complete action from a role without facility.complete', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id);
    // viewer has view but not complete.
    $this->actingAs(makeUser('viewer', [$asset->id]));

    asTenant($asset, function () use ($order) {
        Livewire::test(ListFacilityWorkOrders::class)
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

    expect(FacilityWorkOrderItem::where('facility_work_order_id', $order->id)->count())->toBe(1);
});

it('freezes the checklist add action on a terminal work order', function () {
    $asset = makeAsset();
    $order = makeWorkOrder($asset->id, ['status' => 'done', 'completed_at' => now()]);
    $this->actingAs(makeUser('operations', [$asset->id]));

    checklistRM($order)->assertTableActionHidden('add_item');
});
