<?php

use App\Filament\Portal\Resources\Invoices\Pages\ListInvoices;
use App\Models\Payment;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    config(['integrations.paymob.enabled' => false]);
    ensureAllPropertiesAsset();

    $this->tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $this->tenant);
    $this->invoice = makeInvoice($lease, ['status' => 'issued', 'paid_amount' => 0, 'balance' => 11400]);

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->tenant), 'portal');
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('demo pay marks the invoice paid and records a captured payment', function () {
    Livewire::test(ListInvoices::class)
        ->callTableAction('payDemo', $this->invoice)
        ->assertHasNoTableActionErrors();

    $this->invoice->refresh();
    expect((float) $this->invoice->balance)->toBe(0.0);
    expect($this->invoice->status)->toBe('paid');
    expect(
        Payment::where('tenant_id', $this->tenant->id)
            ->where('status', 'captured')
            ->where('gateway', 'demo')
            ->exists()
    )->toBeTrue();
});

it('hides the demo pay action once Paymob is enabled (real flow takes over)', function () {
    config(['integrations.paymob.enabled' => true]);

    Livewire::test(ListInvoices::class)
        ->assertTableActionHidden('payDemo', $this->invoice)
        ->assertTableActionVisible('payNow', $this->invoice);
});
