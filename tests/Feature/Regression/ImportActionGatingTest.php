<?php

use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/*
| Regression guard — bulk-import actions must be gated on `imports.execute`.
|
| Found during the 2026-07 Eltizam FRD gap analysis: ListTenants and ListLeases
| wired ImportAction with NO visibility/authorization gate at all, while the
| sibling ListUnits had one. A `viewer` — spec'd read-only, holding only *.view —
| was offered a bulk-import button that writes records.
|
| The first fix gated them on {module}.create, and this file's own note said that
| was interim: "the dedicated import permission arrives with the mall_admin role
| (plan phase 8) ... this test asserts the escalation is closed, not the final FRD
| role model." Phase 8 landed, so the final model is now asserted here — the
| expectation below flipped deliberately, not by accident.
|
| FR-USR-02: "restrict data import/upload to Admin users only; all other roles may
| export/download but not import." A manager or the leasing team holding `.create`
| is NOT an admin. See App\Support\Imports and ImportIsAdminOnlyTest (which also
| carries the conformance gate over every ImportAction in the app).
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

/* ---- creating is not importing (FR-USR-02) ------------------------------ */

it('hides the import action from a role that may create but is not an admin', function () {
    // THE EXPECTATION THAT FLIPPED. `leasing` holds tenants.create + leases.create and was
    // therefore offered bulk import — because the interim gate treated import as a flavour of
    // create. It is not: creating a tenant is one considered row; one wrong CSV column rewrites
    // hundreds, and the damage surfaces later in the billing. The FRD reserves it for admins.
    $asset = makeAsset(['code' => 'IGA']);
    $this->actingAs(makeUser('leasing', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListTenants::class)->assertActionHidden('import');
        Livewire::test(ListLeases::class)->assertActionHidden('import');
    });
});

it('shows the import action to a mall admin', function () {
    // …and the right still exists for the role the FRD gives it to, or we would have "fixed" the
    // escalation by deleting the feature.
    $asset = makeAsset(['code' => 'IGM']);
    $this->actingAs(makeUser('mall_admin', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListTenants::class)->assertActionVisible('import');
        Livewire::test(ListLeases::class)->assertActionVisible('import');
        Livewire::test(ListUnits::class)->assertActionVisible('import');
    });
});

it('refuses to mount the import action for a manager invoked directly', function () {
    // Hiding is a UI affordance; a crafted Livewire call skips the rendered page. The server-side
    // half must refuse too — for a manager now, not just a viewer, since a manager is exactly who
    // the old gate let through.
    $asset = makeAsset(['code' => 'IGX']);
    $this->actingAs(makeUser('manager', [$asset->id]));

    asTenant($asset, function () {
        $component = Livewire::test(ListTenants::class)->mountAction('import');

        expect($component->get('mountedActions'))->toBe([]);
    });
});
