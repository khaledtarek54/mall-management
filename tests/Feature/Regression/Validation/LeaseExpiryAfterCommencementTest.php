<?php

/*
|--------------------------------------------------------------------------
| Regression — LeaseResource expiry_date must be AFTER commencement_date
|--------------------------------------------------------------------------
| Guards the `->after('commencement_date')` rule on the expiry_date
| DatePicker in LeaseForm (Section "term"). Drives the real CreateLease
| Filament page through Livewire, mirroring MultiUnitLeaseFormScenarioTest's
| leaseCreatePayload + fillForm + ->call('create') idiom and
| InvoiceDateValidationTest's assertHasFormErrors/assertHasNoFormErrors style.
|
|   - expiry BEFORE commencement -> form error on expiry_date ('after').
|   - expiry EQUAL  to commencement -> form error on expiry_date ('after').
|   - expiry AFTER  commencement -> no error on expiry_date (lease created).
*/

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Models\Lease;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'HW']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Required Create-lease fields; caller overrides the dates under test. */
function leaseTermPayload(int $unitId, int $tenantId, array $overrides = []): array
{
    return array_merge([
        'unit_id' => $unitId,
        'tenant_id' => $tenantId,
        'status' => 'active',
        'commencement_date' => '2026-06-01',
        'expiry_date' => '2027-05-31',
        'term_months' => 12,
        'base_rent_monthly' => 5000,
        'service_charge_monthly' => 1000,
    ], $overrides);
}

it('rejects an expiry date before the commencement date', function () {
    $unit = makeUnit($this->asset, ['code' => 'X-01', 'status' => 'vacant']);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm(leaseTermPayload($unit->id, $tenant->id, [
            'commencement_date' => '2026-06-01',
            'expiry_date' => '2026-05-01',
        ]))
        ->call('create')
        ->assertHasFormErrors(['expiry_date' => 'after']);

    expect(Lease::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

it('rejects an expiry date equal to the commencement date', function () {
    $unit = makeUnit($this->asset, ['code' => 'X-02', 'status' => 'vacant']);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm(leaseTermPayload($unit->id, $tenant->id, [
            'commencement_date' => '2026-06-01',
            'expiry_date' => '2026-06-01',
        ]))
        ->call('create')
        ->assertHasFormErrors(['expiry_date' => 'after']);

    expect(Lease::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

it('accepts an expiry date after the commencement date', function () {
    $unit = makeUnit($this->asset, ['code' => 'X-03', 'status' => 'vacant']);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm(leaseTermPayload($unit->id, $tenant->id, [
            'commencement_date' => '2026-06-01',
            'expiry_date' => '2027-05-31',
        ]))
        ->call('create')
        ->assertHasNoFormErrors(['expiry_date']);

    expect(Lease::where('tenant_id', $tenant->id)->exists())->toBeTrue();
});
