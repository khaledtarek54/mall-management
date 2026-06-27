<?php

use App\Notifications\PaymentReceivedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // Demo endpoint is only active while Paymob is disabled.
    config(['integrations.paymob.enabled' => false]);
});

it('marks the invoice paid through the real capture path and notifies the tenant', function () {
    Notification::fake();

    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease, ['status' => 'issued', 'paid_amount' => 0, 'balance' => 11400]);

    $this->postJson("/api/v1/me/invoices/{$invoice->id}/pay-demo", [], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.status', 'captured')
        ->assertJsonPath('data.amount', 11400)
        ->assertJsonPath('data.allocations.0.invoiceId', $invoice->id);

    // Invoice cleared via the standard recompute (captured allocation).
    $invoice->refresh();
    expect((float) $invoice->balance)->toBe(0.0);
    expect($invoice->status)->toBe('paid');

    Notification::assertSentTo($tenant, PaymentReceivedNotification::class);

    $this->assertDatabaseHas('payments', [
        'tenant_id' => $tenant->id,
        'status' => 'captured',
        'gateway' => 'demo',
    ]);
});

it('refuses an invoice with no outstanding balance', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease, ['status' => 'paid', 'paid_amount' => 11400, 'balance' => 0]);

    $this->postJson("/api/v1/me/invoices/{$invoice->id}/pay-demo", [], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonPath('error', 'no_balance');
});

it('returns 404 for another tenant\'s invoice (no cross-tenant existence leak)', function () {
    $tenant = makeTenant();
    $otherInvoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    $this->postJson("/api/v1/me/invoices/{$otherInvoice->id}/pay-demo", [], apiHeaders($tenant))
        ->assertStatus(404);
});

it('is disabled (409) once Paymob is enabled', function () {
    config(['integrations.paymob.enabled' => true]);

    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    $this->postJson("/api/v1/me/invoices/{$invoice->id}/pay-demo", [], apiHeaders($tenant))
        ->assertStatus(409)
        ->assertJsonPath('error', 'use_real_payment');
});
