<?php

/*
|--------------------------------------------------------------------------
| Regression — Payment form tenant select is property-scoped (cross-property IDOR)
|--------------------------------------------------------------------------
| BUG (fixed): the admin Payment form let you allocate another property's
| tenant/invoices. The PaymentForm 'tenant_id' relationship select is now
| modifyQueryUsing-scoped to TenantScope::visibleAssetIds() via
| leases.unit.asset_id. With propertyA as the active Filament tenant, the
| tenant select must offer propertyA's leased tenant and EXCLUDE propertyB's.
|
| We mount the real CreatePayment Livewire page and read the live options off
| the mounted 'tenant_id' component (same pattern as
| MultiUnitLeaseFormScenarioTest reading getOptions()).
*/

use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->propertyA = makeAsset(['code' => 'PROP-A']);
    $this->propertyB = makeAsset(['code' => 'PROP-B']);

    // Tenant A is leased in property A; tenant B is leased in property B.
    $this->tenantA = makeTenant(['name' => 'Property A Tenant']);
    $this->tenantB = makeTenant(['name' => 'Property B Tenant']);

    makeLease(makeUnit($this->propertyA, ['code' => 'A-01']), $this->tenantA);
    makeLease(makeUnit($this->propertyB, ['code' => 'B-01']), $this->tenantB);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->propertyA);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * The tenant ids a mounted CreatePayment page would OFFER for a typed query.
 *
 * Reads the SEARCH path, not `getOptions()`. Since 2026-08-17 the tenant picker is an
 * `EntitySelect`: it searches server-side over the folded `search_text` blob and holds no options
 * until something is typed, because a tenant table grows and is never loaded whole into a page.
 * `getOptions()` therefore returns `[]` for every tenant — which would make the exclusion below
 * pass for the wrong reason, and is exactly why the inclusion is asserted alongside it.
 */
function paymentTenantOptionKeys(Testable $page, string $query): array
{
    $component = $page->instance()->form->getComponent('tenant_id');

    return array_map('intval', array_keys($component->getSearchResults($query)));
}

it('offers the current property tenant and excludes another property tenant in the payment form', function () {
    $page = Livewire::test(CreatePayment::class)->assertOk();

    expect(paymentTenantOptionKeys($page, $this->tenantA->name))->toContain($this->tenantA->id)
        ->and(paymentTenantOptionKeys($page, $this->tenantB->name))->not->toContain($this->tenantB->id);
});
