<?php

use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant(['name' => 'Current Tenant Co']);
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, ['status' => 'active']);

    // Second tenant who is genuinely delinquent.
    $this->delinquentTenant = makeTenant(['name' => 'Delinquent Tenant Co']);
    $this->delinquentUnit = makeUnit($this->asset);
    $this->delinquentLease = makeLease($this->delinquentUnit, $this->delinquentTenant, ['status' => 'active']);
    makeInvoice($this->delinquentLease, [
        'status' => 'overdue',
        'balance' => 50000,
        'due_date' => now()->subDays(15),
        'paid_amount' => 0,
        'total' => 50000,
    ]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
});

it('Tenant::isDelinquent returns the expected values', function () {
    expect($this->tenant->fresh()->isDelinquent())->toBeFalse();
    expect($this->delinquentTenant->fresh()->isDelinquent())->toBeTrue();
});

it('the delinquency filter narrows the listing to past-due tenants only', function () {
    asTenant($this->asset, function () {
        Livewire::test(ListTenants::class)
            ->assertCanSeeTableRecords([$this->tenant, $this->delinquentTenant])
            ->filterTable('is_delinquent', true)
            ->assertCanSeeTableRecords([$this->delinquentTenant])
            ->assertCanNotSeeTableRecords([$this->tenant]);
    });
});

it('the delinquency filter set to false hides past-due tenants', function () {
    asTenant($this->asset, function () {
        Livewire::test(ListTenants::class)
            ->filterTable('is_delinquent', false)
            ->assertCanSeeTableRecords([$this->tenant])
            ->assertCanNotSeeTableRecords([$this->delinquentTenant]);
    });
});
