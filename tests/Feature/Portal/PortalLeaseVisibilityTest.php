<?php

use App\Filament\Portal\Resources\Leases\LeaseResource;
use App\Filament\Portal\Resources\Leases\Pages\ListLeases;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A tenant can see their OWN lease in the portal (module 03). The portal doc claims a tenant "sees
 * the same lease, invoices and maintenance requests" — but there was no lease surface at all: a
 * tenant could not see their terms or download their signed lease. These pin the read-only,
 * tenant-scoped view (never another tenant's lease).
 */
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('portal')));
afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->tenantA = makeTenant(['name' => 'Cafe Crema']);
    $this->tenantB = makeTenant(['name' => 'Optix Eyewear']);
    $this->leaseA = makeLease(makeUnit($this->asset), $this->tenantA, ['status' => 'active', 'base_rent_monthly' => 25000]);
    $this->leaseB = makeLease(makeUnit($this->asset), $this->tenantB, ['status' => 'active', 'base_rent_monthly' => 40000]);
});

it('shows a tenant their own lease, read-only', function () {
    $this->actingAs(makeTenantUser($this->tenantA), 'portal');

    Livewire::test(ListLeases::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->leaseA]);

    // The portal never edits the lease — it is the operator's record, shown to the tenant.
    expect(LeaseResource::canCreate())->toBeFalse()
        ->and(LeaseResource::canEdit($this->leaseA))->toBeFalse()
        ->and(LeaseResource::canDelete($this->leaseA))->toBeFalse();
});

it('never shows one tenant another tenant’s lease', function () {
    $this->actingAs(makeTenantUser($this->tenantA), 'portal');

    Livewire::test(ListLeases::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->leaseA])
        ->assertCanNotSeeTableRecords([$this->leaseB]);

    // And the scoped query itself excludes the other tenant's lease.
    expect(LeaseResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($this->leaseA->id)
        ->not->toContain($this->leaseB->id);
});

it('a read-only (non-admin) portal user can still view their lease', function () {
    // Lease visibility is read-only for everyone — even a viewer-only portal user sees it.
    $viewer = makeTenantUser($this->tenantA, false);
    $this->actingAs($viewer, 'portal');

    Livewire::test(ListLeases::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->leaseA]);
});
