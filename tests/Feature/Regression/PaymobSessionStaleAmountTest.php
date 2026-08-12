<?php

use App\Models\Payment;
use App\Services\Paymob\PaymobPaymentInitiator;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| REGRESSION — Paymob stale-session overpayment.
|--------------------------------------------------------------------------
| BUG (fixed): PaymobPaymentInitiator reused an 'initiated' session even
| after the invoice balance dropped (e.g. a credit/partial payment landed),
| handing back the OLD higher-amount gateway token and overcharging the
| tenant.
|
| FIX: findReusableSession() returns null unless
|   round(payment.amount, 2) === round(invoice.balance, 2)
| so a balance change forces a FRESH session bound to the new amount.
|
| These tests would FAIL before the fix (the second start() would return the
| stale 1000-amount session with reused=true) and PASS now.
*/

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
    $this->invoice = makeInvoice($this->lease, ['total' => 1000, 'balance' => 1000]);
});

/**
 * Drop the invoice's balance the way production does — by SETTLING part of it.
 *
 * `$invoice->update(['balance' => 700])` stopped working in `35ab6524`, and correctly so: the
 * `saving` hook re-derives `balance` from `total − paid_amount` on every write, because the four
 * settlement channels are the only things allowed to move it. A direct write is now a silent
 * no-op — so this test was setting a column no code path writes, and the guard it names was no
 * longer being exercised at all. (CLAUDE.md: a fixture that writes what no form, service or seeder
 * writes is a test that is green over dead code.)
 */
function settlePartOf(\App\Models\Invoice $invoice, float $amount): void
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => $amount,
        'currency' => 'EGP',
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now()->toDateString(),
    ]);

    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $invoice->recomputeTotals();
}

it('cuts a fresh session for the new lower balance instead of reusing the stale higher-amount one', function () {
    // One shared Http::fake hands out distinct order ids via a sequence —
    // a second Http::fake() would NOT override the first orders stub.
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::sequence()
            ->push(['id' => 5001])
            ->push(['id' => 5002]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::sequence()
            ->push(['token' => 'PAY-KEY-STALE'])
            ->push(['token' => 'PAY-KEY-FRESH']),
    ]);

    // First session: full 1000 balance → an 'initiated' Payment for 1000.
    $first = app(PaymobPaymentInitiator::class)->start($this->invoice);
    expect($first['order_id'])->toBe(5001);
    expect($first['reused'])->toBeFalse();
    expect((float) Payment::find($first['payment_id'])->amount)->toBe(1000.0);

    // A partial payment lands and the balance drops to 700. The old gateway token is still
    // bound to 1000.
    settlePartOf($this->invoice->fresh(), 300);

    // Second tap: must NOT reuse the stale 1000 token — it would overcharge. `fresh()` because the
    // settlement moved the row, not this in-memory instance; a request loads the invoice anew, and
    // comparing against a stale copy would make this pass for the wrong reason.
    $second = app(PaymobPaymentInitiator::class)->start($this->invoice->fresh());

    expect($second['reused'])->toBeFalse();
    expect($second['payment_id'])->not->toBe($first['payment_id']);
    expect($second['order_id'])->toBe(5002);

    // The fresh Payment is bound to the CURRENT 700 balance, not the stale 1000.
    $freshPayment = Payment::find($second['payment_id']);
    expect((float) $freshPayment->amount)->toBe(700.0);

    // Two distinct Paymob payments now exist (no reuse happened).
    expect(Payment::where('gateway', 'paymob')->count())->toBe(2);
});

it('findReusableSession returns null on the amount-mismatch branch', function () {
    // Drive only the first session, then mutate the balance and assert the
    // private branch directly via reflection — a focused guard on the exact
    // fix even if the HTTP path ever changes.
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 6001]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    $initiator = app(PaymobPaymentInitiator::class);
    $initiator->start($this->invoice);

    $reflect = new ReflectionMethod($initiator, 'findReusableSession');
    $reflect->setAccessible(true);

    // While the balance still matches (1000), the session IS reusable.
    expect($reflect->invoke($initiator, $this->invoice->fresh()))->not->toBeNull();

    // Drop the balance to 700 — the stored 1000 amount no longer matches.
    settlePartOf($this->invoice->fresh(), 300);

    // The amount-mismatch branch must return null so start() falls through to
    // a fresh session.
    expect($reflect->invoke($initiator, $this->invoice->fresh()))->toBeNull();
});
