<?php

/*
|--------------------------------------------------------------------------
| Guard — Credit Note form tenant select is property-scoped (cross-property IDOR)
|--------------------------------------------------------------------------
| The admin CreditNote form 'tenant_id' select is sourced from
| App\Support\Search\OptionDisplay's tenant PICKER_SCOPE, which constrains the
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
use App\Models\CreditNote;
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

    // Restricted user: 'accounting' role has credit_notes.create but is NOT
    // super_admin, so scoping is enforced (super_admin bypasses it).
    $this->actingAs(makeUser('accounting', [$this->propertyA->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->propertyA);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Resolve the option keys for the tenant_id select on a mounted CreateCreditNote page. */
function creditNoteTenantOptionKeys(Testable $page): array
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

it('recomputes the note total from its items on create — a tampered header total cannot post', function () {
    // The subtotal/total/balance fields are readOnly (not disabled), so a crafted submit can set
    // them directly; the journalizer posts $note->total. The create page must re-derive them from
    // the persisted line items so a fabricated total can never reach the GL. (Reachable form path.)
    Livewire::test(CreateCreditNote::class)
        ->fillForm([
            'tenant_id' => $this->tenantA->id,
            'reason' => 'adjustment',
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => [
                ['description' => 'Overcharge', 'amount' => 100, 'vat_rate' => 0, 'total' => 100],
            ],
            // Tampered header — items sum to 100, but these claim 999,999.
            'subtotal' => 999999, 'vat_amount' => 0, 'total' => 999999, 'balance' => 999999,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $note = CreditNote::where('tenant_id', $this->tenantA->id)->latest('id')->first();
    expect((float) $note->total)->toBe(100.0)      // re-derived from items, NOT the tampered 999,999
        ->and((float) $note->subtotal)->toBe(100.0)
        ->and((float) $note->balance)->toBe(100.0);
});
