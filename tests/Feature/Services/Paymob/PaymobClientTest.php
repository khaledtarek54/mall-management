<?php

use App\Services\Paymob\PaymobClient;
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
});

function paymobClient(): PaymobClient
{
    return PaymobClient::fromConfig();
}

it('authenticate returns the bearer token from the auth endpoint', function () {
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER-FROM-PAYMOB']),
    ]);

    expect(paymobClient()->authenticate())->toBe('BEARER-FROM-PAYMOB');
});

it('createOrder converts EGP major to amount_cents and returns the numeric order_id', function () {
    Http::fake([
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 4242]),
    ]);

    ensureAllPropertiesAsset();
    $asset = makeAsset();
    $tenant = makeTenant();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, $tenant);
    $invoice = makeInvoice($lease, ['total' => 1000, 'balance' => 1000]);

    expect(paymobClient()->createOrder('bearer', 1000.50, $invoice))->toBe(4242);

    Http::assertSent(function ($request) {
        return $request['amount_cents'] === 100050 && $request['currency'] === 'EGP';
    });
});

it('requestPaymentKey passes integration_id + amount_cents and returns the payment token', function () {
    Http::fake([
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAYMENT-KEY-OK']),
    ]);

    $token = paymobClient()->requestPaymentKey('bearer', 4242, 50, ['name' => 'Cafe Crema', 'email' => 'c@c.test', 'phone_number' => '+201111']);
    expect($token)->toBe('PAYMENT-KEY-OK');

    Http::assertSent(function ($request) {
        return $request['integration_id'] === '123456' && $request['amount_cents'] === 5000;
    });
});

it('iframeUrl is the documented format', function () {
    expect(paymobClient()->iframeUrl('TOKEN-XYZ'))
        ->toBe('https://sandbox.paymob.test/api/acceptance/iframes/999?payment_token=TOKEN-XYZ');
});

it('buildPaymentSession wires the 3 calls in order and returns the redirect URL', function () {
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 9090]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    ensureAllPropertiesAsset();
    $asset = makeAsset();
    $tenant = makeTenant();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, $tenant);
    $invoice = makeInvoice($lease, ['total' => 250, 'balance' => 250]);

    $session = paymobClient()->buildPaymentSession($invoice);

    expect($session['payment_token'])->toBe('PAY-KEY');
    expect($session['order_id'])->toBe(9090);
    expect($session['iframe_url'])->toContain('payment_token=PAY-KEY');
});

it('buildPaymentSession refuses an invoice with zero balance', function () {
    ensureAllPropertiesAsset();
    $asset = makeAsset();
    $tenant = makeTenant();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, $tenant);
    $invoice = makeInvoice($lease, ['total' => 100, 'paid_amount' => 100, 'balance' => 0]);

    paymobClient()->buildPaymentSession($invoice);
})->throws(\RuntimeException::class, 'no balance');

it('verifyHmac accepts a callback signed with our hmac_secret', function () {
    $obj = [
        'amount_cents' => 25000, 'created_at' => '2026-06-01T10:00:00.000000Z', 'currency' => 'EGP',
        'error_occured' => false, 'has_parent_transaction' => false, 'id' => 11223344,
        'integration_id' => 123456, 'is_3d_secure' => true, 'is_auth' => false,
        'is_capture' => true, 'is_refunded' => false, 'is_standalone_payment' => false,
        'is_voided' => false, 'order' => ['id' => 9090], 'owner' => 5,
        'pending' => false, 'source_data' => ['pan' => '****', 'sub_type' => 'MasterCard', 'type' => 'card'],
        'success' => true,
    ];
    $fields = [
        $obj['amount_cents'], $obj['created_at'], $obj['currency'], 'false', 'false',
        $obj['id'], $obj['integration_id'], 'true', 'false', 'true', 'false', 'false', 'false',
        $obj['order']['id'], $obj['owner'], 'false',
        $obj['source_data']['pan'], $obj['source_data']['sub_type'], $obj['source_data']['type'],
        'true',
    ];
    $signature = hash_hmac('sha512', implode('', $fields), 'TEST-HMAC-SECRET');

    expect(paymobClient()->verifyHmac(['obj' => $obj], $signature))->toBeTrue();
    expect(paymobClient()->verifyHmac(['obj' => $obj], 'bad-signature'))->toBeFalse();
});

it('fromConfig throws when credentials are missing', function () {
    config([
        'integrations.paymob.api_key' => '',
        'integrations.paymob.integration_id' => '',
        'integrations.paymob.iframe_id' => '',
    ]);

    PaymobClient::fromConfig();
})->throws(\RuntimeException::class, 'Paymob credentials missing');

it('authenticate raises a clear error when Paymob returns a non-2xx', function () {
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['detail' => 'bad key'], 401),
    ]);

    paymobClient()->authenticate();
})->throws(\RuntimeException::class, 'Paymob authenticate failed');
