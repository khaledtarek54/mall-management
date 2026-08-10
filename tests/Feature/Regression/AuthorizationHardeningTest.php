<?php

use App\Filament\Admin\RelationManagers\AssetStaffRelationManager;
use App\Filament\Admin\RelationManagers\PortalUsersRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\CreditNote;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/*
| Regression guards for the 2026-07 authorization-hardening pass (audit HIGHs):
|   R1 — asset-staff attach/detach must require roles.edit (grants asset access)
|   R2 — credit-note "apply" must never touch a cross-property invoice
|   R3 — unit-create asset picker must be scoped to the user's properties
|   R4 — lease "generate invoice" must require leases.generate_invoice
|   R6 — force-delete must keep the self-delete guard
|   S2 — portal is_admin may only be set by super_admin
*/

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/* ---- R1: asset-staff attach/detach gated on roles.edit ------------------ */

it('hides asset-staff attach/detach from a user without roles.edit', function () {
    $asset = makeAsset();
    $staff = makeUser('operations');
    $asset->staff()->attach($staff->id, ['assigned_at' => now()]);

    $this->actingAs(makeUser('leasing')); // no roles.edit

    Livewire::test(AssetStaffRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass' => EditAsset::class,
    ])
        ->assertTableActionHidden('attach')
        ->assertTableActionHidden('detach', $staff);
});

it('shows asset-staff attach/detach to super_admin (roles.edit)', function () {
    $asset = makeAsset();
    $staff = makeUser('operations');
    $asset->staff()->attach($staff->id, ['assigned_at' => now()]);

    $this->actingAs(makeUser('super_admin'));

    Livewire::test(AssetStaffRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass' => EditAsset::class,
    ])
        ->assertTableActionVisible('attach')
        ->assertTableActionVisible('detach', $staff);
});

/* ---- R6: force-delete self-guard ---------------------------------------- */

it('forbids a super_admin from force-deleting their own account', function () {
    $admin = makeUser('super_admin');
    $this->actingAs($admin);
    $other = makeUser('viewer');

    expect(UserResource::canForceDelete($admin))->toBeFalse();
    expect(UserResource::canForceDelete($other))->toBeTrue();
});

/* ---- S2: portal is_admin is super_admin-only ---------------------------- */

it('prevents a non-super_admin from promoting a portal user to admin', function () {
    $tenant = makeTenant();
    $portalUser = makeTenantUser($tenant, isAdmin: false);

    $this->actingAs(makeUser('leasing')); // has tenants.edit, not super_admin

    Livewire::test(PortalUsersRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('edit', $portalUser, data: [
            'name' => $portalUser->name,
            'email' => $portalUser->email,
            'is_admin' => true, // tampered — must be ignored (field not dehydrated)
        ])
        ->assertHasNoTableActionErrors();

    expect($portalUser->fresh()->is_admin)->toBeFalse();
});

it('lets a super_admin set portal is_admin', function () {
    $tenant = makeTenant();
    $portalUser = makeTenantUser($tenant, isAdmin: false);

    $this->actingAs(makeUser('super_admin'));

    Livewire::test(PortalUsersRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('edit', $portalUser, data: [
            'name' => $portalUser->name,
            'email' => $portalUser->email,
            'is_admin' => true,
        ])
        ->assertHasNoTableActionErrors();

    expect($portalUser->fresh()->is_admin)->toBeTrue();
});

/* ---- R4: lease generate-invoice gated on leases.generate_invoice -------- */

it('hides lease generate-invoice from a user lacking leases.generate_invoice', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset)); // status active

    $user = makeUser('viewer', [$asset->id]); // panel access + *.view, no leases.edit
    $user->givePermissionTo('leases.edit');   // can open EditLease, still no generate_invoice
    $this->actingAs($user);

    asTenant($asset, function () use ($lease) {
        Livewire::test(EditLease::class, ['record' => $lease->id])
            ->assertActionHidden('generateInvoice');
    });
});

it('shows lease generate-invoice to a user with the permission', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($lease) {
        Livewire::test(EditLease::class, ['record' => $lease->id])
            ->assertActionVisible('generateInvoice');
    });
});

/* ---- R2: credit-note apply is property-scoped --------------------------- */

it('refuses to apply a credit note to an invoice in a non-visible property', function () {
    $tenant = makeTenant();
    $assetA = makeAsset(['code' => 'CPA']);
    $assetB = makeAsset(['code' => 'CPB']);
    $leaseA = makeLease(makeUnit($assetA), $tenant);
    $leaseB = makeLease(makeUnit($assetB), $tenant);
    $invA = makeInvoice($leaseA, ['status' => 'issued', 'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000]);
    $invB = makeInvoice($leaseB, ['status' => 'issued', 'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000]);

    $note = CreditNote::create([
        'tenant_id' => $tenant->id,
        'lease_id' => $leaseA->id,
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'reason' => 'adjustment',
        'subtotal' => 500, 'vat_amount' => 0, 'total' => 500,
        'applied_amount' => 0, 'balance' => 500, 'currency' => 'EGP',
    ]);

    // A property-restricted user (assigned to A only) — super_admin is unrestricted
    // by design, so the guard is a no-op for them; the finding is about scoped users.
    $this->actingAs(makeUser('manager', [$assetA->id]));

    // Tenant pinned to property A → property B invoice is outside the visible set.
    // Assert the security OUTCOME (B never charged) — the picker scoping and the
    // server-side abort() guard together must prevent the cross-property apply,
    // regardless of which layer catches it.
    asTenant($assetA, function () use ($note, $invB) {
        try {
            Livewire::test(EditCreditNote::class, ['record' => $note->id])
                ->callAction('apply', data: ['invoice_id' => $invB->id, 'amount' => 100]);
        } catch (\Throwable $e) {
            // abort(403) may surface as an exception depending on the Livewire path.
        }
    });

    // Property B invoice must be untouched; the credit note must not have been applied.
    expect((float) $invB->fresh()->balance)->toEqualWithDelta(1000.0, 0.001);
    expect((float) $note->fresh()->applied_amount)->toEqualWithDelta(0.0, 0.001);

    // Same-property invoice A → applies normally.
    asTenant($assetA, function () use ($note, $invA) {
        Livewire::test(EditCreditNote::class, ['record' => $note->id])
            ->callAction('apply', data: ['invoice_id' => $invA->id, 'amount' => 100])
            ->assertHasNoActionErrors();
    });

    expect((float) $invA->fresh()->balance)->toEqualWithDelta(900.0, 0.001);
    expect((float) $note->fresh()->applied_amount)->toEqualWithDelta(100.0, 0.001);
});

/* ---- R3: unit-create asset picker is property-scoped -------------------- */

it('scopes the unit-create asset picker to the user\'s properties in All-Properties mode', function () {
    $all = ensureAllPropertiesAsset();
    $assetA = makeAsset(['code' => 'UFA']);
    $assetB = makeAsset(['code' => 'UFB']);

    $user = makeUser('leasing', [$assetA->id]); // units.create; assigned to A only
    $this->actingAs($user);

    asTenant($all, function () use ($assetB) {
        Livewire::test(CreateUnit::class)
            ->fillForm([
                'asset_id' => $assetB->id, // outside the user's set
                'code' => 'Z-01',
                'category' => 'retail',
                'area_sqm' => 25,
                'status' => 'vacant',
            ])
            ->call('create')
            ->assertHasFormErrors(['asset_id']);
    });
});

it('lets a restricted user edit an in-scope unit without dropping its asset', function () {
    // Guards against the scoping change orphaning a valid current value on edit:
    // a restricted user can only ever open units within their set (BypassesScopingOnAll
    // pins record resolution too), so the current asset is always a valid option.
    $all = ensureAllPropertiesAsset();
    $assetA = makeAsset(['code' => 'UEA']);
    $assetB = makeAsset(['code' => 'UEB']);
    $unit = makeUnit($assetA, ['code' => 'IN-1', 'area_sqm' => 100]);

    $user = makeUser('leasing', [$assetA->id, $assetB->id]); // both properties visible
    $this->actingAs($user);

    asTenant($all, function () use ($unit) {
        Livewire::test(EditUnit::class, ['record' => $unit->id])
            ->fillForm(['area_sqm' => 125])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    // The point of this test is that the EDIT succeeds without the form dropping `asset_id`; the
    // field it happens to change is incidental. `floor` was a free-text column and is now a
    // relation to the property's floor register, so the edit moves an attribute that still exists.
    expect((float) $unit->fresh()->area_sqm)->toBe(125.0);
    expect((int) $unit->fresh()->asset_id)->toBe($assetA->id); // asset retained, not orphaned
});
