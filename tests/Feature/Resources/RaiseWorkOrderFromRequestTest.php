<?php

use App\Filament\Admin\Resources\TenantRequests\Pages\ListTenantRequests;
use App\Models\FacilityWorkOrder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * The "Raise work order" action on a tenant request (module 11 → 26). The service is covered in
 * RequestToWorkOrderLinkScenarioTest; this pins the surface staff actually use and its gating.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'RWO']);
    $this->unit = makeUnit($this->asset, ['code' => 'U-1']);
    $this->tenant = makeTenant();
    makeLease($this->unit, $this->tenant);
    $this->request = makeTenantRequest([
        'unit_id' => $this->unit->id, 'tenant_id' => $this->tenant->id,
        'title' => 'AC down', 'description' => 'No cooling', 'category' => 'hvac', 'priority' => 'high',
    ]);
});

it('raises a linked work order from the action', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id])); // holds facility.create

    asTenant($this->asset, function () {
        Livewire::test(ListTenantRequests::class)
            ->callTableAction('raise_work_order', $this->request, data: [
                'execution_type' => 'internal',
                'title' => 'Fix the AC',
                'description' => 'Recharge and test',
                'priority' => 'high',
            ])
            ->assertHasNoTableActionErrors();
    });

    $wo = FacilityWorkOrder::where('tenant_request_id', $this->request->id)->sole();
    expect($wo->asset_id)->toBe($this->asset->id);
    expect($wo->work_order_type)->toBe(FacilityWorkOrder::TYPE_CM);
    expect($this->request->fresh()->hasLinkedWorkOrder())->toBeTrue();
});

it('hides the action from a role that can see requests but not create work orders', function () {
    // viewer holds every `.view` (so it reaches the requests page) but not
    // facility.create — triaging a ticket and raising facility work are different
    // rights. leasing would 403 on the page itself, which is a coarser guard already covered.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListTenantRequests::class)
            ->assertTableActionHidden('raise_work_order', $this->request);
    });
});

it('offers the action while the request is open', function () {
    // The FR-USR-06 flow: raise the work order while the request is in progress, then resolve the
    // request evidenced by that work order. So the action must be available during the open life.
    // (A closed request is excluded from the default table view entirely, so its action is
    // doubly unreachable — the isOpen() guard on the action is belt-and-braces.)
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListTenantRequests::class)
            ->assertTableActionVisible('raise_work_order', $this->request);
    });
});
