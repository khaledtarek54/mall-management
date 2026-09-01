<?php

use App\Filament\Portal\Resources\Invoices\Pages\ListInvoices;
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

// ============================================================================
// The portal invoice table is the same tenant surface as the API, so it hides
// the same thing — see App\Support\TenantVisibility.
// ============================================================================

it('never shows a tenant a draft invoice in the portal, but does show an issued one', function () {
    $this->actingAs(makeTenantUser($this->tenantA), 'portal');

    $draft = makeInvoice($this->leaseA, ['status' => 'draft']);
    $issued = makeInvoice($this->leaseA, ['status' => 'issued']);

    // The control matters as much as the refusal: a table scoped to nothing would satisfy the
    // first assertion on its own.
    Livewire::test(ListInvoices::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$issued])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('never shows a tenant a lease nobody has put to them', function () {
    // A DRAFT lease is terms still being written — the retailer reading their own rent, term and
    // deposit off a negotiation and reasonably treating it as settled. The portal scoped by
    // `tenant_id` alone, which answers *whose row is this* and not *has it been put to them*.
    //
    // Driven through the real page, like its neighbour above. An earlier version built the query in
    // the test and asserted that Eloquent's `whereNotIn` works — it passed with the fix deleted.
    //
    // `pending_approval` stays VISIBLE, deliberately: twelve places treat it as a live tenancy — it
    // may be terminated, given rent relief, extended, re-priced, hold a parking bay and mark it
    // off-market, and it counts as committed revenue. Hiding it would leave a retailer holding a
    // bay under a lease they cannot see.
    $draft = makeLease(makeUnit($this->asset), $this->tenantA, ['status' => 'draft']);
    $pending = makeLease(makeUnit($this->asset), $this->tenantA, ['status' => 'pending_approval']);
    $ended = makeLease(makeUnit($this->asset), $this->tenantA, ['status' => 'terminated']);

    $this->actingAs(makeTenantUser($this->tenantA), 'portal');

    Livewire::test(ListLeases::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$draft])
        // …and the controls: a lease under approval is live, and one that ENDED still explains a
        // tenancy the tenant remembers.
        ->assertCanSeeTableRecords([$pending, $ended, $this->leaseA]);
});

it('keeps a draft lease off the mobile login payload too', function () {
    // The portal and /api/v1 are the same surface with different renderers. `LoginTenantAction`
    // listed EVERY lease — id, mall, unit number and term dates — so the mobile login offered a
    // picker entry for terms nobody had put to the tenant.
    $draft = makeLease(makeUnit($this->asset), $this->tenantA, ['status' => 'draft']);
    $live = makeLease(makeUnit($this->asset), $this->tenantA, ['status' => 'active']);

    $ids = $this->tenantA->leases()->visibleToTenant()->pluck('id');

    expect($ids)->not->toContain($draft->id)
        ->and($ids)->toContain($live->id);
});
