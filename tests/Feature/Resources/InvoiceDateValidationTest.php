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

function fillInvoice(array $overrides = []): array
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

it('rejects a due date before the issue date', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(fillInvoice([
            'lease_id' => $this->lease->id,
            'tenant_id' => $this->lease->tenant_id,
            'issue_date' => '2026-02-10',
            'due_date' => '2026-02-05',
        ]))
        ->call('create')
        ->assertHasFormErrors(['due_date' => 'after']);
});

it('rejects a due date equal to the issue date', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(fillInvoice([
            'lease_id' => $this->lease->id,
            'tenant_id' => $this->lease->tenant_id,
            'issue_date' => '2026-02-10',
            'due_date' => '2026-02-10',
        ]))
        ->call('create')
        ->assertHasFormErrors(['due_date' => 'after']);
});

it('accepts a due date after the issue date', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(fillInvoice([
            'lease_id' => $this->lease->id,
            'tenant_id' => $this->lease->tenant_id,
            'issue_date' => '2026-02-10',
            'due_date' => '2026-02-20',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();
});
