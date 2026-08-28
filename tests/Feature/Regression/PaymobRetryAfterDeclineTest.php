<?php

/*
|--------------------------------------------------------------------------
| A declined card is not the end of the order (2026-08-17)
|--------------------------------------------------------------------------
| Found in the ops log of a live Paymob session, not by reading code:
|
|     18:53:27  paymob.session_started   order 589424727, EGP 140,300, channel payment_link
|     18:53:34  → declined (txn 229844534, success=false, error_occured=false)
|     18:54:32  Paymob callback for unknown order — {"order_id":589424727,"txn_id":803955240}
|
| The second delivery is the SAME order with a DIFFERENT transaction — a retry on the hosted page
| after the decline. It was discarded, for two independent reasons, either of which alone loses it:
|
|   1. the lookup keyed on `gateway_transaction_id`, which the first callback had already promoted
|      from `paymob:order:{id}` to `paymob:txn:{txn}:order:{id}` — so the row could not be found
|   2. even once found, `failed` was treated as terminal, so the capture would have been skipped as
|      "already processed"
|
| If that retry succeeded, the tenant was charged and the invoice stayed open. Nobody could tell,
| because the unknown-order log recorded the ids and NOT whether the transaction had succeeded —
| a dropped decline and a dropped payment were the same line.
|
| What stays terminal, deliberately: `captured` (a late failure must never un-capture money that
| arrived) and `refunded` (an operator's decision that no gateway delivery may reverse).
*/

use App\Models\Payment;
use App\Notifications\PaymentReceivedNotification;
use App\Services\VoidPaymentService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

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

/** Post a signed callback for an order/transaction, as Paymob does. */
function postPaymobCallback(int $orderId, int $txnId, bool $success): TestResponse
{
    $payload = paymobCallbackPayload(orderId: $orderId, txnId: $txnId, success: $success);

    return test()->postJson(route('paymob.callback', ['hmac' => signPaymobPayload($payload)]), $payload);
}

it('captures a retry that succeeds under the same order after a decline', function () {
    Notification::fake();

    $payment = seedInitiatedPayment($this->invoice, orderId: 589424727);

    // The decline. Nothing is collected, the invoice stays open — all correct.
    postPaymobCallback(589424727, 229844534, success: false)->assertOk();

    expect($payment->fresh()->status)->toBe('failed')
        ->and((float) $this->invoice->fresh()->balance)->toBe(1200.0);

    // The shopper presses "try again" on the same page. New transaction, same order.
    postPaymobCallback(589424727, 803955240, success: true)
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'captured']);

    $payment->refresh();
    $this->invoice->refresh();

    expect($payment->status)->toBe('captured')
        ->and($payment->gateway_transaction_id)->toBe('paymob:txn:803955240:order:589424727')
        ->and((float) $this->invoice->paid_amount)->toBe(1200.0)
        ->and($this->invoice->status)->toBe('paid');

    // The tenant is told, exactly as on a first-time capture.
    Notification::assertSentTo($this->tenant, PaymentReceivedNotification::class);
});

it('finds the payment by ORDER even after the first callback rewrote the reference', function () {
    $payment = seedInitiatedPayment($this->invoice, orderId: 589424727);

    postPaymobCallback(589424727, 229844534, success: false)->assertOk();

    // This is the state the lookup used to fail on: the stored reference no longer equals
    // `paymob:order:{id}`, which is the only thing the query compared against.
    expect($payment->fresh()->gateway_transaction_id)->toBe('paymob:txn:229844534:order:589424727');

    // Not "unknown_order" — the whole defect in one assertion.
    postPaymobCallback(589424727, 803955240, success: true)
        ->assertOk()
        ->assertJsonMissing(['skipped' => 'unknown_order']);
});

it('never un-captures a payment when a later callback reports failure', function () {
    $payment = seedInitiatedPayment($this->invoice, orderId: 700100);

    postPaymobCallback(700100, 111, success: true)->assertOk();
    expect($payment->fresh()->status)->toBe('captured');

    // A late or duplicated failure delivery. The money arrived; the books must not move.
    postPaymobCallback(700100, 222, success: false)
        ->assertOk()
        ->assertJson(['skipped' => 'already_processed']);

    expect($payment->fresh()->status)->toBe('captured')
        ->and((float) $this->invoice->fresh()->balance)->toBe(0.0);
});

it('never resurrects a voided payment', function () {
    $payment = seedInitiatedPayment($this->invoice, orderId: 700200);

    postPaymobCallback(700200, 333, success: true)->assertOk();
    app(VoidPaymentService::class)->void($payment->fresh(), 'Reversed at the counter');

    // `voided` since 2026-08-28. What this test is about is unchanged: an operator's reversal is
    // not overruled by a later gateway delivery.
    expect($payment->fresh()->status)->toBe('voided')
        ->and((float) $this->invoice->fresh()->balance)->toBe(1200.0);

    // A reversal is an operator's decision. A gateway delivery does not overrule it.
    postPaymobCallback(700200, 444, success: true)
        ->assertOk()
        ->assertJson(['skipped' => 'already_processed']);

    expect($payment->fresh()->status)->toBe('voided')
        ->and((float) $this->invoice->fresh()->balance)->toBe(1200.0);
});

it('stays idempotent when the SAME transaction is delivered twice', function () {
    Notification::fake();

    $payment = seedInitiatedPayment($this->invoice, orderId: 700300);

    postPaymobCallback(700300, 555, success: true)->assertOk();
    postPaymobCallback(700300, 555, success: true)
        ->assertOk()
        ->assertJson(['skipped' => 'already_processed']);

    // One capture, one receipt — a redelivery must not bill or notify twice.
    expect((float) $this->invoice->fresh()->paid_amount)->toBe(1200.0)
        ->and(Payment::count())->toBe(1);

    Notification::assertSentToTimes($this->tenant, PaymentReceivedNotification::class, 1);
});
