<?php

use App\Models\Payment;
use App\Notifications\PaymentReceivedNotification;
use App\Services\Paymob\PaymobPaymentInitiator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

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
    $this->invoice = makeInvoice($this->lease, ['total' => 1200, 'balance' => 1200]);
});

/**
 * Spin up an 'initiated' Paymob payment for the test invoice the same way
 * the Pay Now action would, then return the Payment model + the Paymob
 * order_id used as its session anchor.
 */
function seedInitiatedPayment(\App\Models\Invoice $invoice, int $orderId = 8888): \App\Models\Payment
{
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => $orderId]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    app(PaymobPaymentInitiator::class)->start($invoice);

    return Payment::where('gateway_transaction_id', "paymob:order:{$orderId}")->firstOrFail();
}

function paymobCallbackPayload(int $orderId, int $txnId, bool $success = true): array
{
    return [
        'obj' => [
            'amount_cents' => 120000,
            'created_at' => '2026-06-01T10:00:00.000000Z',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => $txnId,
            'integration_id' => 123456,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => true,
            'is_refunded' => false,
            'is_standalone_payment' => false,
            'is_voided' => false,
            'order' => ['id' => $orderId],
            'owner' => 5,
            'pending' => false,
            'source_data' => ['pan' => '****', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => $success,
        ],
    ];
}

function signPaymobPayload(array $payload, string $secret = 'TEST-HMAC-SECRET'): string
{
    $obj = $payload['obj'];
    $boolStr = fn ($v) => $v ? 'true' : 'false';
    $fields = [
        $obj['amount_cents'], $obj['created_at'], $obj['currency'],
        $boolStr($obj['error_occured']), $boolStr($obj['has_parent_transaction']),
        $obj['id'], $obj['integration_id'],
        $boolStr($obj['is_3d_secure']), $boolStr($obj['is_auth']),
        $boolStr($obj['is_capture']), $boolStr($obj['is_refunded']),
        $boolStr($obj['is_standalone_payment']), $boolStr($obj['is_voided']),
        $obj['order']['id'], $obj['owner'], $boolStr($obj['pending']),
        $obj['source_data']['pan'], $obj['source_data']['sub_type'], $obj['source_data']['type'],
        $boolStr($obj['success']),
    ];

    return hash_hmac('sha512', implode('', $fields), $secret);
}

it('a valid HMAC + success=true captures the payment and updates the invoice', function () {
    Notification::fake();

    $payment = seedInitiatedPayment($this->invoice, orderId: 9001);
    $payload = paymobCallbackPayload(orderId: 9001, txnId: 555111);
    $signature = signPaymobPayload($payload);

    $this->postJson(route('paymob.callback', ['hmac' => $signature]), $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'captured']);

    $payment->refresh();
    expect($payment->status)->toBe('captured');
    expect($payment->gateway_transaction_id)->toBe('paymob:txn:555111:order:9001');

    $this->invoice->refresh();
    expect((float) $this->invoice->paid_amount)->toBe(1200.0);
    expect((float) $this->invoice->balance)->toBe(0.0);
    expect($this->invoice->status)->toBe('paid');

    Notification::assertSentTo($this->tenant, PaymentReceivedNotification::class);
});

it('rejects callbacks with a bad HMAC and does not touch the payment', function () {
    $payment = seedInitiatedPayment($this->invoice, orderId: 9002);
    $payload = paymobCallbackPayload(orderId: 9002, txnId: 555112);

    $this->postJson(route('paymob.callback', ['hmac' => 'TOTALLY-WRONG']), $payload)
        ->assertStatus(401)
        ->assertJson(['ok' => false]);

    expect($payment->fresh()->status)->toBe('initiated');
});

it('returns 200 and skips for an unknown order_id (idempotent ack)', function () {
    $payload = paymobCallbackPayload(orderId: 424242, txnId: 1);

    $this->postJson(route('paymob.callback', ['hmac' => signPaymobPayload($payload)]), $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'skipped' => 'unknown_order']);
});

it('is idempotent on already-captured payments', function () {
    $payment = seedInitiatedPayment($this->invoice, orderId: 9003);
    $payment->update([
        'status' => 'captured',
        'gateway_transaction_id' => 'paymob:txn:1:order:9003',
    ]);

    $payload = paymobCallbackPayload(orderId: 9003, txnId: 2);

    // The Payment no longer carries the bare 'paymob:order:9003' anchor (it
    // was promoted at capture time), so the lookup falls through to the
    // unknown-order branch — the contract is the same: 200 + no double-work.
    $this->postJson(route('paymob.callback', ['hmac' => signPaymobPayload($payload)]), $payload)
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($payment->fresh()->status)->toBe('captured');
});

it('marks the payment failed when Paymob reports success=false', function () {
    Notification::fake();

    $payment = seedInitiatedPayment($this->invoice, orderId: 9004);
    $payload = paymobCallbackPayload(orderId: 9004, txnId: 555113, success: false);

    $this->postJson(route('paymob.callback', ['hmac' => signPaymobPayload($payload)]), $payload)
        ->assertOk()
        ->assertJson(['status' => 'failed']);

    $payment->refresh();
    expect($payment->status)->toBe('failed');

    // Invoice balance must stay open — failed payments don't allocate.
    $this->invoice->refresh();
    expect((float) $this->invoice->paid_amount)->toBe(0.0);
    expect((float) $this->invoice->balance)->toBe(1200.0);

    Notification::assertNothingSent();
});

it('GET /paymob/return bounces back to the portal with a success flash', function () {
    $this->get(route('paymob.return', ['success' => 'true']))
        ->assertRedirect('/portal/invoices')
        ->assertSessionHas('status');
});

it('GET /paymob/return on failure flashes an error', function () {
    $this->get(route('paymob.return', ['success' => 'false']))
        ->assertRedirect('/portal/invoices')
        ->assertSessionHas('error');
});

it('captures once and receipts once when the gateway delivers the same callback twice', function () {
    // Paymob retries. A retry that arrives while the first delivery is still running used to pass
    // the terminal-status check twice, because that check sat OUTSIDE the transaction with no lock
    // on the row — textbook check-then-act. It cannot double-COLLECT (both deliveries address one
    // Payment row, and recomputeTotals is idempotent), but it can fire the `saved` hook twice on
    // the same captured transition and send the tenant two receipts for one payment.
    //
    // What this test proves is the OUTCOME — one capture, one receipt, whatever arrives twice. What
    // it cannot prove is the inner re-check itself: reproducing a genuine overlap needs two
    // processes, and deleting the re-check leaves this green. The lock is the guard; this is its
    // witness at the level a single-process sqlite suite can actually reach, and saying so is
    // better than implying coverage that is not there.
    Notification::fake();

    $payment = seedInitiatedPayment($this->invoice, orderId: 9101);
    $payload = paymobCallbackPayload(orderId: 9101, txnId: 77);
    $signature = signPaymobPayload($payload);

    $this->postJson(route('paymob.callback', ['hmac' => $signature]), $payload)->assertOk();
    $this->postJson(route('paymob.callback', ['hmac' => $signature]), $payload)->assertOk();

    expect($payment->fresh()->status)->toBe('captured')
        ->and(\App\Models\Payment::where('gateway', 'paymob')->count())->toBe(1);

    Notification::assertSentToTimes(
        $this->invoice->tenant,
        \App\Notifications\PaymentReceivedNotification::class,
        1,
    );
});
