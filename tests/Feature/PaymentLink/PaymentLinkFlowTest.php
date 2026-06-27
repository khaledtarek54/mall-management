<?php

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('integrations.paymob.enabled', true);
    config()->set('integrations.paymob.api_key', 'k');
    config()->set('integrations.paymob.integration_id', '111');
    config()->set('integrations.paymob.iframe_id', '222');
    config()->set('integrations.paymob.hmac_secret', 'secret');
});

function payLinkInvoice(): Invoice
{
    return makeInvoice(makeLease(makeUnit(makeAsset()))); // balance 11400
}

function fakePaymob(int $orderId): void
{
    Http::fake([
        '*/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        '*/api/ecommerce/orders' => Http::response(['id' => $orderId]),
        '*/api/acceptance/payment_keys' => Http::response(['token' => 'PAYKEY']),
    ]);
}

it('shows the public pay page for a valid token', function () {
    $invoice = payLinkInvoice();

    $this->get(route('pay.show', ['token' => $invoice->paymentLinkToken()]))
        ->assertOk()
        ->assertSee('Amount due')
        ->assertSee($invoice->number);
});

it('404s an unknown pay token (no enumeration)', function () {
    $this->get('/pay/'.str_repeat('x', 48))->assertNotFound();
});

it('redirects an already-settled invoice to the status page', function () {
    $invoice = payLinkInvoice();
    $token = $invoice->paymentLinkToken();
    $invoice->update(['balance' => 0, 'status' => 'paid']);

    $this->get(route('pay.show', ['token' => $token]))
        ->assertRedirect(route('pay.status', ['token' => $token]));
});

it('starts a payment_link session and redirects to the gateway iframe', function () {
    fakePaymob(777);
    $invoice = payLinkInvoice();

    $resp = $this->post(route('pay.start', ['token' => $invoice->paymentLinkToken()]));

    $resp->assertRedirect();
    expect($resp->headers->get('Location'))->toContain('iframes/222?payment_token=PAYKEY');

    $payment = Payment::where('channel', Payment::CHANNEL_LINK)->first();
    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(11400.0);
});

it('shows success on the status page once the balance is cleared', function () {
    $invoice = payLinkInvoice();
    $invoice->update(['balance' => 0, 'status' => 'paid']);

    $this->get(route('pay.status', ['token' => $invoice->paymentLinkToken()]))
        ->assertOk()
        ->assertSee('Payment successful');
});

it('routes a payment_link browser return to the public status page', function () {
    fakePaymob(888);
    $invoice = payLinkInvoice();
    $token = $invoice->paymentLinkToken();
    $this->post(route('pay.start', ['token' => $token]));

    $this->get('/paymob/return?success=true&order=888')
        ->assertRedirect(route('pay.status', ['token' => $token]));
});

it('routes a non-link return to the authenticated portal', function () {
    $this->get('/paymob/return?success=true&order=99999')
        ->assertRedirect('/portal/invoices');
});

it('hides the Apple Pay button unless configured', function () {
    $invoice = payLinkInvoice();
    $token = $invoice->paymentLinkToken();

    $this->get(route('pay.show', ['token' => $token]))->assertDontSee('apple_pay');

    config()->set('integrations.paymob.apple_pay_integration_id', '333');
    $this->get(route('pay.show', ['token' => $token]))->assertSee('apple_pay');
});

it('serves a 404 for the Apple Pay domain-association file until provisioned', function () {
    $this->get('/.well-known/apple-developer-merchantid-domain-association')->assertNotFound();
});
