<?php

use App\Filament\Portal\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Portal\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Portal\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Portal GATING — admin tenant users may write; everyone else is read-only.
|--------------------------------------------------------------------------
| Net-new over TenantUserGatingTest / PanelResourcesTest:
|   • the ViewInvoice "Pay Now" AND "Pay Demo" header actions are visible to an
|     admin and HIDDEN to a non-admin (toggling integrations.paymob.enabled to
|     exercise each pay path), mounted on the admin's OWN invoice; and
|   • mounting the CreateTenantRequest page as a non-admin is forbidden.
|
| The portal scopes via the logged-in user, not Filament tenancy, so we only
| set the portal as the current panel (so Livewire mounts portal pages) and
| authenticate against the 'portal' guard.
*/

beforeEach(function () {
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('portal'));

    $this->tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $this->tenant);
    // Outstanding balance > 0 so both pay actions clear their balance guard;
    // the role/config gate is then the ONLY thing toggling visibility.
    $this->invoice = makeInvoice($lease, ['status' => 'issued', 'paid_amount' => 0, 'balance' => 11400]);
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

/*
| ---------------------------------------------------------------------------
| canCreate / canViewAny across both write-capable portal resources.
| ---------------------------------------------------------------------------
*/

it('grants an admin tenant user canCreate on both write resources', function () {
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    expect(TenantRequestResource::canCreate())->toBeTrue()
        ->and(TenantSalesDeclarationResource::canCreate())->toBeTrue()
        ->and(TenantRequestResource::canViewAny())->toBeTrue()
        ->and(TenantSalesDeclarationResource::canViewAny())->toBeTrue();
});

it('keeps a non-admin tenant user read-only: canViewAny but not canCreate', function () {
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: false), 'portal');

    // Viewing is shared across all portal users…
    expect(TenantRequestResource::canViewAny())->toBeTrue()
        ->and(TenantSalesDeclarationResource::canViewAny())->toBeTrue()
        // …but submitting is admin-only.
        ->and(TenantRequestResource::canCreate())->toBeFalse()
        ->and(TenantSalesDeclarationResource::canCreate())->toBeFalse();
});

/*
| ---------------------------------------------------------------------------
| ViewInvoice header actions — Pay Now (Paymob enabled) path.
| ---------------------------------------------------------------------------
*/

it('shows Pay Now to an admin on their own invoice when Paymob is enabled', function () {
    config(['integrations.paymob.enabled' => true]);
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertActionVisible('payNow')   // live gateway path → visible for admin
        ->assertActionHidden('payDemo');  // demo path suppressed while gateway is on
});

it('hides Pay Now from a non-admin even with Paymob enabled', function () {
    config(['integrations.paymob.enabled' => true]);
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: false), 'portal');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertActionHidden('payNow')
        ->assertActionHidden('payDemo');
});

/*
| ---------------------------------------------------------------------------
| ViewInvoice header actions — Pay Demo (Paymob disabled) path.
| ---------------------------------------------------------------------------
*/

it('shows Pay Demo to an admin on their own invoice when Paymob is disabled', function () {
    config(['integrations.paymob.enabled' => false]);
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertActionVisible('payDemo')  // demo capture path → visible for admin
        ->assertActionHidden('payNow');   // live path suppressed while gateway is off
});

it('hides Pay Demo from a non-admin even with Paymob disabled', function () {
    config(['integrations.paymob.enabled' => false]);
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: false), 'portal');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertActionHidden('payDemo')
        ->assertActionHidden('payNow');
});

/*
| ---------------------------------------------------------------------------
| Hard gate: the create page itself 403s for a non-admin (canCreate=false →
| CreateRecord::authorizeAccess() aborts), not merely a hidden button.
| ---------------------------------------------------------------------------
*/

it('forbids a non-admin from mounting the CreateTenantRequest page', function () {
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: false), 'portal');

    Livewire::test(CreateTenantRequest::class)
        ->assertForbidden();
});

it('lets an admin mount the CreateTenantRequest page', function () {
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    Livewire::test(CreateTenantRequest::class)
        ->assertSuccessful();
});
