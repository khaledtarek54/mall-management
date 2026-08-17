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

it('creates an initiated Payment + allocates the full balance and returns the session', function () {
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 7777]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    $session = app(PaymobPaymentInitiator::class)->start($this->invoice);

    expect($session['iframe_url'])->toBe('https://sandbox.paymob.test/api/acceptance/iframes/999?payment_token=PAY-KEY');
    expect($session['payment_token'])->toBe('PAY-KEY');
    expect($session['order_id'])->toBe(7777);
    expect($session['reused'])->toBeFalse();
    expect($session['payment_id'])->toBeInt();

    $payment = Payment::find($session['payment_id']);
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

it('reuses a recent initiated session instead of burning a new Paymob order', function () {
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 7779]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY-1']),
    ]);

    $first = app(PaymobPaymentInitiator::class)->start($this->invoice);
    $second = app(PaymobPaymentInitiator::class)->start($this->invoice);

    expect($second['payment_id'])->toBe($first['payment_id']);
    expect($second['order_id'])->toBe($first['order_id']);
    expect($second['reused'])->toBeTrue();
    expect(Payment::where('gateway', 'paymob')->count())->toBe(1);

    // Only 3 calls total — the second start() must not have hit Paymob.
    Http::assertSentCount(3);
});

it('cuts a fresh session once the reuse window has passed', function () {
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::sequence()
            ->push(['id' => 7780])
            ->push(['id' => 7781]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::sequence()
            ->push(['token' => 'PAY-KEY-A'])
            ->push(['token' => 'PAY-KEY-B']),
    ]);

    $first = app(PaymobPaymentInitiator::class)->start($this->invoice);

    // Backdate the stale Payment past the reuse window.
    Payment::where('id', $first['payment_id'])->update([
        'created_at' => now()->subSeconds(PaymobPaymentInitiator::REUSE_WINDOW_SECONDS + 60),
    ]);

    $second = app(PaymobPaymentInitiator::class)->start($this->invoice);

    expect($second['payment_id'])->not->toBe($first['payment_id']);
    expect($second['order_id'])->toBe(7781);
    expect($second['reused'])->toBeFalse();
});
