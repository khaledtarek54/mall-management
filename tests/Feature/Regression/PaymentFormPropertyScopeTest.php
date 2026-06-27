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

/** Resolve the option keys for the tenant_id select on a mounted CreatePayment page. */
function paymentTenantOptionKeys(\Livewire\Features\SupportTesting\Testable $page): array
{
    $component = $page->instance()->form->getComponent('tenant_id');

    return array_map('intval', array_keys($component->getOptions()));
}

it('offers the current property tenant and excludes another property tenant in the payment form', function () {
    $page = Livewire::test(CreatePayment::class)->assertOk();

    $options = paymentTenantOptionKeys($page);

    expect($options)->toContain($this->tenantA->id)      // property A tenant — offered
        ->and($options)->not->toContain($this->tenantB->id); // property B tenant — excluded (no IDOR)
});
