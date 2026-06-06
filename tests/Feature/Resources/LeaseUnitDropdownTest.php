<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'HW']);
    $this->vacant = makeUnit($this->asset, ['code' => 'HW-VAC', 'status' => 'vacant']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('rejects creating an active lease on a unit that already has one', function () {
    $tenant = makeTenant();
    $otherTenant = makeTenant();
    makeLease($this->vacant, $tenant, ['status' => 'active']);

    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->vacant->id,
            'tenant_id' => $otherTenant->id,
            'status' => 'active',
            'commencement_date' => '2026-06-01',
            'expiry_date' => '2027-05-31',
            'term_months' => 12,
            'base_rent_monthly' => 5000,
            'service_charge_monthly' => 1000,
        ])
        ->call('create')
        ->assertHasFormErrors(['unit_id']);
});

it('allows creating a draft lease on a unit that already has an active lease', function () {
    $tenant = makeTenant();
    $renewalTenant = makeTenant();
    makeLease($this->vacant, $tenant, ['status' => 'active']);

    Livewire::test(CreateLease::class)
        ->fillForm([
            'show_occupied_units' => true,
            'unit_id' => $this->vacant->id,
            'tenant_id' => $renewalTenant->id,
            'status' => 'draft',
            'commencement_date' => '2027-06-01',
            'expiry_date' => '2028-05-31',
            'term_months' => 12,
            'base_rent_monthly' => 5500,
            'service_charge_monthly' => 1100,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('lets edit lease keep its own (occupied) unit', function () {
    $tenant = makeTenant();
    $lease = makeLease($this->vacant, $tenant, ['status' => 'active']);
    // LeaseObserver flipped the unit to occupied; verify and continue.
    expect($this->vacant->fresh()->status)->toBe('occupied');

    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->fillForm([
            'status' => 'active',
            'commencement_date' => '2026-02-01',
            'expiry_date' => '2027-01-31',
            'term_months' => 12,
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});
