<?php

use App\Models\Payment;

function makeAllocatedPayment($tenant, $invoice, float $amount = 5000): Payment
{
    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => $amount,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $payment->recomputeAllocatedInvoices();

    return $payment;
}

it('lists the tenant\'s payments with allocations', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));
    makeAllocatedPayment($tenant, $invoice, 4000);

    // Foreign payment must not leak.
    $other = makeTenant();
    makeAllocatedPayment($other, makeInvoice(makeLease(makeUnit(makeAsset()), $other)));

    $response = $this->getJson('/api/v1/me/payments', apiHeaders($tenant))->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    $response->assertJsonPath('data.0.amount', 4000)
        ->assertJsonPath('data.0.allocations.0.allocatedAmount', 4000)
        ->assertJsonPath('data.0.allocations.0.invoiceNumber', $invoice->number);
});

it('shows a single payment', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));
    $payment = makeAllocatedPayment($tenant, $invoice);

    $this->getJson("/api/v1/me/payments/{$payment->id}", apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.id', $payment->id);
});

it('returns 404 for another tenant\'s payment', function () {
    $tenant = makeTenant();
    $other = makeTenant();
    $payment = makeAllocatedPayment($other, makeInvoice(makeLease(makeUnit(makeAsset()), $other)));

    $this->getJson("/api/v1/me/payments/{$payment->id}", apiHeaders($tenant))->assertNotFound();
});
