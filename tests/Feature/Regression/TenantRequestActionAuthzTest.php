<?php

use App\Filament\Admin\Resources\TenantRequests\Pages\ListTenantRequests;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Models\Asset;
use App\Models\Department;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Tenant-request write actions (changeStatus / assign / redirect) gated their permission + terminal
 * check ONLY in visible() (via canEdit) — non-compliant with the project invariant that every write
 * action gate in BOTH visible() and action() (modules 08 CAM / 09 Sales follow it). This brings them
 * into compliance: the same canEdit predicate re-asserted in authorize() + abort_unless(action()), so
 * a read-only viewer / owner (who hold requests.view but not requests.edit) can never re-status,
 * reassign, or reroute a request.
 *
 * NOTE (empirically verified 2026-07-26): in the INSTALLED Filament version, mountAction()/TestAction
 * DOES respect visible() — a visible-only action was already blocked from this exact mountAction+
 * callMountedAction vector (proven by reverting the gate and watching this test still pass, and the
 * same on CamActionAuthzTest). So the old "visible() is not a dispatch gate" premise no longer holds
 * for this vector; the action() gate here is defense-in-depth + invariant compliance (robust to a
 * Filament change, a visible() refactor, or a raw-HTTP crafting the test helper doesn't exercise),
 * NOT a fix for an exploit reproducible via mountAction today.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Authenticate as a role scoped to the asset, then scope the panel to it. */
function trActAs(string $role, int $assetId): void
{
    test()->actingAs(makeUser($role, [$assetId]));
    Filament::setTenant(Asset::find($assetId));
}

it('refuses a read-only VIEWER re-assigning a request via a crafted dispatch', function () {
    $req = makeTenantRequest(['status' => 'submitted']);
    $assetId = $req->unit->asset_id;
    $assignee = makeUser('operations', [$assetId]);
    $req->update(['assigned_to' => $assignee->id]);

    trActAs('viewer', $assetId);
    expect(TenantRequestResource::canEdit($req->fresh()))->toBeFalse(); // viewer lacks requests.edit

    Livewire::test(ListTenantRequests::class)
        ->mountAction(TestAction::make('assign')->table($req))
        ->callMountedAction();

    expect($req->fresh()->assigned_to)->toBe($assignee->id); // unchanged — dispatch refused
});

it('refuses a read-only OWNER re-routing a request via a crafted dispatch', function () {
    $req = makeTenantRequest(['status' => 'submitted']);
    $assetId = $req->unit->asset_id;
    $dept = Department::create(['name' => 'Ops', 'asset_id' => $assetId]);
    $req->update(['department_id' => $dept->id]);

    trActAs('owner', $assetId);
    expect(TenantRequestResource::canEdit($req->fresh()))->toBeFalse();

    Livewire::test(ListTenantRequests::class)
        ->mountAction(TestAction::make('redirect')->table($req))
        ->callMountedAction();

    expect($req->fresh()->department_id)->toBe($dept->id); // unchanged — dispatch refused
});

it('still lets an OPERATIONS user (holds requests.edit) assign — the gate is authz, not a blanket block', function () {
    $req = makeTenantRequest(['status' => 'submitted']);
    $assetId = $req->unit->asset_id;
    trActAs('operations', $assetId);
    expect(TenantRequestResource::canEdit($req->fresh()))->toBeTrue();

    Livewire::test(ListTenantRequests::class)
        ->mountAction(TestAction::make('assign')->table($req))
        ->callMountedAction(); // no assignee → clears to null, which the service allows

    // The action ran (no 403): assign(null) is a legal no-op clear for an authorized user.
    expect($req->fresh()->assigned_to)->toBeNull();
});
