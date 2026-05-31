<?php

use App\Models\Payment;
use App\Services\Paymob\PaymobPaymentInitiator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'integrations.paymob.base_url' => 'https://sandbox.paymob.test',
        'integrations.paymob.api_key' => 'TEST-API-KEY',
        'integrations.paymob.integration_id' => '123456',
        'integrations.paymob.iframe_id' => '999',
        'integrations.paymob.currency' => 'EGP',
        'integrations.paymob.hmac_secret' => 'TEST-HMAC-SECRET',
    ]);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant);
    $this->invoice = makeInvoice($this->lease, ['total' => 500, 'balance' => 500]);
});

it('creates an initiated Payment + allocates the full balance and returns the iframe URL', function () {
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 7777]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    $url = app(PaymobPaymentInitiator::class)->start($this->invoice);

    expect($url)->toBe('https://sandbox.paymob.test/api/acceptance/iframes/999?payment_token=PAY-KEY');

    $payment = Payment::where('gateway', 'paymob')->latest()->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('initiated');
    expect($payment->gateway_transaction_id)->toBe('paymob:order:7777');
    expect((float) $payment->amount)->toBe(500.0);
    expect($payment->invoices()->first()?->id)->toBe($this->invoice->id);
    expect((float) $payment->invoices()->first()?->pivot->allocated_amount)->toBe(500.0);
});

it('leaves the invoice balance untouched until the payment is captured', function () {
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 7778]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    app(PaymobPaymentInitiator::class)->start($this->invoice);

    // recomputeTotals filters by payments.status = 'captured', so an
    // 'initiated' allocation must not bump paid_amount.
    expect((float) $this->invoice->fresh()->paid_amount)->toBe(0.0);
    expect((float) $this->invoice->fresh()->balance)->toBe(500.0);
});
