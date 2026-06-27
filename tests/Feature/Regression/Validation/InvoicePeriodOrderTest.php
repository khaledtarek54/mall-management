<?php

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->hw = makeAsset(['code' => 'HW']);
    $this->lease = makeLease(makeUnit($this->hw, ['code' => 'HW-01']));

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->hw);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function fillInvoicePeriodOrder(array $overrides = []): array
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

it('rejects a period end before the period start', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(fillInvoicePeriodOrder([
            'lease_id' => $this->lease->id,
            'tenant_id' => $this->lease->tenant_id,
            'period_start' => '2026-02-28',
            'period_end' => '2026-02-01',
        ]))
        ->call('create')
        ->assertHasFormErrors(['period_end' => 'after']);
});

it('rejects a period end equal to the period start', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(fillInvoicePeriodOrder([
            'lease_id' => $this->lease->id,
            'tenant_id' => $this->lease->tenant_id,
            'period_start' => '2026-02-15',
            'period_end' => '2026-02-15',
        ]))
        ->call('create')
        ->assertHasFormErrors(['period_end' => 'after']);
});

it('accepts a period end after the period start', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(fillInvoicePeriodOrder([
            'lease_id' => $this->lease->id,
            'tenant_id' => $this->lease->tenant_id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
        ]))
        ->call('create')
        ->assertHasNoFormErrors(['period_end']);
});
