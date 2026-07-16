<?php

use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/*
| Regression guard — bulk-import actions must be gated on {module}.create.
|
| Found during the 2026-07 Eltizam FRD gap analysis: ListTenants and ListLeases
| wired ImportAction with NO visibility/authorization gate at all, while the
| sibling ListUnits had one. A `viewer` — spec'd read-only, holding only *.view —
| was offered a bulk-import button that writes records.
|
| Guards FR-USR-02 (import restricted; other roles export-only). Note the gate is
| {module}.create today, NOT admin-only: the dedicated import permission arrives
| with the mall_admin role (plan phase 8). This test asserts the escalation is
| closed, not the final FRD role model.
*/

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/* ---- viewer (read-only) must never see an import button ----------------- */

it('hides the tenant import action from a read-only viewer', function () {
    $asset = makeAsset(['code' => 'IGT']);
    $this->actingAs(makeUser('viewer', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListTenants::class)->assertActionHidden('import');
    });
});

it('hides the lease import action from a read-only viewer', function () {
    $asset = makeAsset(['code' => 'IGL']);
    $this->actingAs(makeUser('viewer', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListLeases::class)->assertActionHidden('import');
    });
});

it('hides the unit import action from a read-only viewer', function () {
    $asset = makeAsset(['code' => 'IGU']);
    $this->actingAs(makeUser('viewer', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListUnits::class)->assertActionHidden('import');
    });
});

/* ---- hiding is not enough: the action must refuse to mount -------------- */

it('refuses to mount the tenant import action for a viewer invoked directly', function () {
    $asset = makeAsset(['code' => 'IGD']);
    $this->actingAs(makeUser('viewer', [$asset->id]));

    // A hidden button is only a UI affordance — assert the server-side half too,
    // since a crafted Livewire call skips the rendered page entirely. Filament
    // refuses the mount *silently* (unmounts + returns null, no exception), so
    // the evidence is an empty mountedActions, not a throw.
    asTenant($asset, function () {
        $component = Livewire::test(ListTenants::class)->mountAction('import');

        expect($component->get('mountedActions'))->toBe([]);
    });
});

/* ---- a role that may create still gets the button ----------------------- */

it('shows the tenant and lease import actions to a role holding create', function () {
    $asset = makeAsset(['code' => 'IGA']);
    $this->actingAs(makeUser('leasing', [$asset->id])); // tenants.create + leases.create

    asTenant($asset, function () {
        Livewire::test(ListTenants::class)->assertActionVisible('import');
        Livewire::test(ListLeases::class)->assertActionVisible('import');
    });
});
