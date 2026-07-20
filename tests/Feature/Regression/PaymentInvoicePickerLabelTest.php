<?php

use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Filament\Admin\Resources\Payments\Schemas\PaymentForm;
use App\Models\Payment;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: the payment allocation picker's options() lists only invoices with balance > 0, so an
 * invoice this payment already FULLY PAID (balance now 0) is not in the list. Without a label
 * resolver, the edit page rendered the raw invoice id ("6") instead of "INV-AW-…". PaymentForm now
 * has getOptionLabelUsing() → invoiceOptionLabel(), which resolves ANY invoice to its number label.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('labels a fully-paid (balance 0) invoice with its number, not its raw id', function () {
    $invoice = makeInvoice($this->lease, ['status' => 'paid', 'total' => 5000, 'balance' => 0, 'paid_amount' => 5000]);

    $m = new ReflectionMethod(PaymentForm::class, 'invoiceOptionLabel');
    $m->setAccessible(true);
    $label = (string) $m->invoke(null, $invoice);

    expect($label)->toContain($invoice->number)      // shows INV-… …
        ->and($label)->not->toBe((string) $invoice->id); // … never the bare id
});

it('renders the edit page of a payment whose allocated invoice is now fully paid', function () {
    $invoice = makeInvoice($this->lease, ['status' => 'issued', 'total' => 5000, 'balance' => 5000, 'paid_amount' => 0]);
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id, 'amount' => 5000, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);
    $invoice->recomputeTotals(); // balance → 0, so it drops out of the picker's options()

    Livewire::test(EditPayment::class, ['record' => $payment->getRouteKey()])->assertOk();
});
