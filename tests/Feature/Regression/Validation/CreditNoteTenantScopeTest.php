<?php

/*
|--------------------------------------------------------------------------
| Guard — Credit Note form tenant select is property-scoped (cross-property IDOR)
|--------------------------------------------------------------------------
| The admin CreditNote form 'tenant_id' select is sourced from
| App\Support\TenantScope::selectableTenantOptions(), which constrains the
| offered tenants to the current user's visible properties via
| leases.unit.asset_id. With propertyA as the active Filament tenant and a
| restricted (non-super_admin) accounting user, the tenant select must offer
| propertyA's leased tenant and EXCLUDE propertyB's leased tenant.
|
| We mount the real CreateCreditNote Livewire page and read the live options
| off the mounted 'tenant_id' component (same pattern as
| PaymentFormPropertyScopeTest reading getOptions()).
*/

use App\Filament\Admin\Resources\CreditNotes\Pages\CreateCreditNote;
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

    // Restricted user: 'accounting' role has credit_notes.create but is NOT
    // super_admin, so scoping is enforced (super_admin bypasses it).
    $this->actingAs(makeUser('accounting', [$this->propertyA->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->propertyA);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Resolve the option keys for the tenant_id select on a mounted CreateCreditNote page. */
function creditNoteTenantOptionKeys(\Livewire\Features\SupportTesting\Testable $page): array
{
    $component = $page->instance()->form->getComponent('tenant_id');

    return array_map('intval', array_keys($component->getOptions()));
}

it('offers the in-scope property tenant on the credit note form', function () {
    $page = Livewire::test(CreateCreditNote::class)->assertOk();

    $options = creditNoteTenantOptionKeys($page);

    // property A tenant — in scope, must be offered.
    expect($options)->toContain($this->tenantA->id);
});

it('excludes a non-visible property tenant from the credit note form', function () {
    $page = Livewire::test(CreateCreditNote::class)->assertOk();

    $options = creditNoteTenantOptionKeys($page);

    // property B tenant — out of scope, must NOT be offered (no IDOR).
    expect($options)->not->toContain($this->tenantB->id);
});
