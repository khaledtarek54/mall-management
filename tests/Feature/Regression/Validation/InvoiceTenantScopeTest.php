<?php

/*
|--------------------------------------------------------------------------
| Regression — Invoice form tenant select is property-scoped (cross-property IDOR)
|--------------------------------------------------------------------------
| GUARD: InvoiceResource 'tenant_id' must be property-scoped. A property-
| restricted user (assigned to a single property) must NOT be able to invoice
| a tenant that belongs to a different property.
|
| The InvoiceForm 'tenant_id' relationship select is modifyQueryUsing-scoped
| to TenantScope::visibleAssetIds() via leases.unit.asset_id. With propertyA
| as the active Filament tenant, the select must offer propertyA's leased
| tenant and EXCLUDE propertyB's.
|
| Mirrors PaymentFormPropertyScopeTest: mount the real CreateInvoice Livewire
| page and read the live options off the mounted 'tenant_id' component, then
| additionally prove that posting the out-of-scope tenant_id is rejected and
| the in-scope tenant_id is accepted.
*/

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
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

    $this->leaseA = makeLease(makeUnit($this->propertyA, ['code' => 'A-01']), $this->tenantA);
    makeLease(makeUnit($this->propertyB, ['code' => 'B-01']), $this->tenantB);

    // A user restricted to property A only — must never reach property B's tenant.
    $this->actingAs(makeUser('manager', [$this->propertyA->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->propertyA);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Resolve the option keys for the tenant_id select on a mounted CreateInvoice page. */
function invoiceTenantOptionKeys(\Livewire\Features\SupportTesting\Testable $page): array
{
    $component = $page->instance()->form->getComponent('tenant_id');

    return array_map('intval', array_keys($component->getOptions()));
}

function fillScopedInvoice(array $overrides = []): array
{
    return array_merge([
        'status' => 'issued',
        'issue_date' => '2026-02-10',
        'due_date' => '2026-02-20',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'items' => [
            ['type' => 'base_rent', 'description' => 'Rent', 'amount' => 1000, 'vat_rate' => 14, 'total' => 1140],
        ],
    ], $overrides);
}

it('offers the current property tenant and excludes another property tenant in the invoice form', function () {
    $page = Livewire::test(CreateInvoice::class)->assertOk();

    $options = invoiceTenantOptionKeys($page);

    expect($options)->toContain($this->tenantA->id)      // property A tenant — offered
        ->and($options)->not->toContain($this->tenantB->id); // property B tenant — excluded (no IDOR)
});

it('rejects an out-of-scope (other property) tenant_id on the invoice form', function () {
    // Submitting property B's tenant — not in the scoped option set — must fail
    // the select's in/exists validation, so no cross-property invoice is created.
    Livewire::test(CreateInvoice::class)
        ->fillForm(fillScopedInvoice([
            'lease_id' => $this->leaseA->id,
            'tenant_id' => $this->tenantB->id,
        ]))
        ->call('create')
        ->assertHasFormErrors(['tenant_id']);
});

it('accepts an in-scope (current property) tenant_id on the invoice form', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(fillScopedInvoice([
            'lease_id' => $this->leaseA->id,
            'tenant_id' => $this->tenantA->id,
        ]))
        ->call('create')
        ->assertHasNoFormErrors(['tenant_id']);
});
