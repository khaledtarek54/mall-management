<?php

use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: PaymentResource `amount` carries `->numeric()->minValue(0)`
 * (app/Filament/Admin/Resources/Payments/Schemas/PaymentForm.php). A negative
 * payment amount must be rejected by the Filament form; a non-negative amount
 * must pass that rule. Proves the guard rejects bad input and accepts good.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'PAY']);
    // A lease ties the tenant to the asset so the property-scoped tenant_id
    // select offers it as a valid option.
    $this->lease = makeLease(makeUnit($this->asset, ['code' => 'PAY-01']));
    $this->tenant = $this->lease->tenant;

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function fillPayment(array $overrides = []): array
{
    return array_merge([
        'payment_date' => '2026-02-10',
        'method' => 'bank_transfer',
        'status' => 'captured',
    ], $overrides);
}

it('rejects a negative payment amount', function () {
    Livewire::test(CreatePayment::class)
        ->fillForm(fillPayment([
            'tenant_id' => $this->tenant->id,
            'amount' => -100,
        ]))
        ->call('create')
        ->assertHasFormErrors(['amount' => 'min']);
});

it('accepts a non-negative payment amount', function () {
    Livewire::test(CreatePayment::class)
        ->fillForm(fillPayment([
            'tenant_id' => $this->tenant->id,
            'amount' => 100,
        ]))
        ->call('create')
        ->assertHasNoFormErrors(['amount']);
});
