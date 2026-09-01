<?php

use App\Http\Controllers\Paymob\CallbackController;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Services\ApplyTenantCreditService;
use App\Services\VoidPaymentService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Testing\TestResponse;

/**
 * Two questions about money that has already landed, each answered from the wrong set.
 *
 * **A RECEIVED payment is terminal, and `received` is three statuses.** The Paymob callback's
 * terminal test read a literal `status === 'captured'`, directly beneath a comment saying — about
 * the reversed half of the same condition — *"enumerate a set like this by asking the model, not by
 * grepping the diff"*. `Payment::RECEIVED_STATUSES` is `captured | reconciled | settled`: money is
 * on the books for all three, and the operator moves a receipt to `reconciled` when they match it
 * against a bank statement. So a late or replayed DECLINE delivery on a reconciled receipt was not
 * skipped — it flipped the row to `failed`, which `Payment::saved` reads as a reversal. The
 * invoice's AR re-opens, the tenant is chased for money sitting in the bank, the bank
 * reconciliation is left pointing at a reversed receipt, and Paymob gets a cheerful 200.
 *
 * **A receipt with no allocations still has a property.** `VoidPaymentService` refuses to refund a
 * receipt whose unallocated surplus has already been drawn down as tenant credit, and scopes that
 * question to the receipt's own mall — under a comment saying a global balance *"would let an
 * unrelated credit at another mall mask that this receipt's own surplus was already spent"*. It then
 * fell through to exactly that global balance whenever the receipt had no invoices, which is the
 * ORDINARY case rather than an exotic one: a cleared SERIES cheque names no invoice, and that is the
 * Egyptian norm.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    // A SIGNED callback, exactly as `PaymobRetryAfterDeclineTest` posts one. Without the secret the
    // endpoint rejects the delivery before it reaches the terminal check — and the first version of
    // this file did precisely that, so its refusal assertion passed while measuring nothing. The
    // control below is what caught it.
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
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);
});

function capturedCardPayment(Invoice $invoice, float $amount): Payment
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => $amount,
        'currency' => 'EGP',
        'method' => 'card',
        'status' => 'initiated',
        'payment_date' => CarbonImmutable::now(),
        'gateway' => 'paymob',
        'channel' => Payment::CHANNEL_LINK,
        'gateway_transaction_id' => 'paymob:txn:555001:order:900001',
        'gateway_response' => ['order_id' => 900001],
    ]);

    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $payment->status = 'captured';
    $payment->save();

    return $payment->refresh();
}

/** A signed DECLINE delivery for the same order, as Paymob posts one. */
function postDecline(int $orderId = 900001, int $txnId = 555002): TestResponse
{
    $payload = paymobCallbackPayload(orderId: $orderId, txnId: $txnId, success: false);

    return test()->postJson(route('paymob.callback', ['hmac' => signPaymobPayload($payload)]), $payload);
}

it('does not un-capture a RECONCILED receipt when a late decline arrives', function () {
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'paid_amount' => 0, 'balance' => 5000,
    ]);

    $payment = capturedCardPayment($invoice, 5000);

    // The operator matches it against the bank statement. Money is on the books; `RECEIVED_STATUSES`
    // says so, and this is the status the literal `=== 'captured'` could not see.
    $payment->update(['status' => 'reconciled']);

    expect($invoice->fresh()->balance)->toEqual(0.0)
        ->and($payment->fresh()->isReceived())->toBeTrue();

    app(CallbackController::class);

    postDecline()->assertOk();

    expect($payment->fresh()->status)->toBe('reconciled')
        // …and the invoice it settled is still settled. This is the assertion that matters: a
        // reversal here re-opens AR and puts the tenant back on the collections worklist for money
        // that is in the bank.
        ->and(round((float) $invoice->fresh()->balance, 2))->toEqual(0.0);
});

it('still records a genuine decline on a payment that never landed — the control', function () {
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'paid_amount' => 0, 'balance' => 5000,
    ]);

    $payment = capturedCardPayment($invoice, 5000);
    $payment->update(['status' => 'initiated']);

    postDecline()->assertOk();

    // Without this the first test would be satisfied by a callback that ignores everything.
    expect($payment->fresh()->status)->toBe('failed');
});

it('scopes a no-allocation receipt to its OWN mall before refusing a void', function () {
    // A cleared SERIES cheque: it names no invoice, so the whole amount is surplus. Until now
    // `$assetIds` came back empty and the guard asked for the tenant's GLOBAL credit balance.
    $otherAsset = makeAsset(['code' => 'BB']);

    $cheque = PostDatedCheque::create([
        'tenant_id' => $this->tenant->id,
        'asset_id' => $this->asset->id,
        'lease_id' => $this->lease->id,
        'bank_name' => 'CIB',
        'cheque_number' => 'CHQ-77001',
        'amount' => 20000,
        'cheque_date' => CarbonImmutable::now()->subDay()->toDateString(),
        'status' => 'deposited',
        'reference' => 'PDC-'.uniqid(),
        'received_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 20000,
        'currency' => 'EGP',
        'method' => 'cheque',
        'status' => 'captured',
        'payment_date' => CarbonImmutable::now(),
    ]);

    // `cleared_payment_id`, not `payment_id` — that is the column `Payment::clearedCheque()` joins
    // on, and it is what carries the property of a receipt with no allocations.
    $cheque->update(['cleared_payment_id' => $payment->id, 'status' => 'cleared']);

    // The premise, asserted rather than assumed: no allocations, and the property is reachable only
    // through the cheque.
    expect($payment->invoices()->count())->toBe(0)
        ->and($payment->originatingAssetId())->toBe($this->asset->id)
        ->and($otherAsset->id)->not->toBe($this->asset->id);

    // The surplus is drawn down against an invoice in THIS mall, so nothing is left to refund.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 20000, 'vat_amount' => 0, 'total' => 20000,
        'paid_amount' => 0, 'balance' => 20000,
    ]);

    app(ApplyTenantCreditService::class)->applyToInvoice($invoice->fresh());

    // **The credit at the OTHER mall is the whole point** — without it the global balance and the
    // scoped one agree, and this test passes whether or not the scope exists. Measured: the first
    // version of this file had no such credit and stayed GREEN with the fix removed.
    $otherCheque = PostDatedCheque::create([
        'tenant_id' => $this->tenant->id,
        'asset_id' => $otherAsset->id,
        'bank_name' => 'NBE',
        'cheque_number' => 'CHQ-88001',
        'amount' => 50000,
        'cheque_date' => CarbonImmutable::now()->subDay()->toDateString(),
        'status' => 'deposited',
        'reference' => 'PDC-'.uniqid(),
        'received_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
    ]);

    $otherPayment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 50000,
        'currency' => 'EGP',
        'method' => 'cheque',
        'status' => 'captured',
        'payment_date' => CarbonImmutable::now(),
    ]);

    $otherCheque->update(['cleared_payment_id' => $otherPayment->id, 'status' => 'cleared']);

    // THIS mall's surplus is spent; the OTHER mall's is untouched and must not stand in for it.
    expect(round((float) $this->tenant->fresh()->creditBalance([$this->asset->id]), 2))->toEqual(0.0)
        ->and(round((float) $this->tenant->fresh()->creditBalance(null), 2))->toBeGreaterThan(0.0);

    expect(fn () => app(VoidPaymentService::class)->void($payment->fresh(), 'Keyed twice'))
        ->toThrow(DomainException::class);
});

it('still allows the void when the surplus really is untouched — the control', function () {
    $cheque = PostDatedCheque::create([
        'tenant_id' => $this->tenant->id,
        'asset_id' => $this->asset->id,
        'lease_id' => $this->lease->id,
        'bank_name' => 'CIB',
        'cheque_number' => 'CHQ-77002',
        'amount' => 20000,
        'cheque_date' => CarbonImmutable::now()->subDay()->toDateString(),
        'status' => 'deposited',
        'reference' => 'PDC-'.uniqid(),
        'received_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 20000,
        'currency' => 'EGP',
        'method' => 'cheque',
        'status' => 'captured',
        'payment_date' => CarbonImmutable::now(),
    ]);

    // `cleared_payment_id`, not `payment_id` — that is the column `Payment::clearedCheque()` joins
    // on, and it is what carries the property of a receipt with no allocations.
    $cheque->update(['cleared_payment_id' => $payment->id, 'status' => 'cleared']);

    // Nothing drew the credit down, so the refusal must NOT fire — a guard that refused every
    // no-allocation void would satisfy the test above while breaking the workflow.
    app(VoidPaymentService::class)->void($payment->fresh(), 'Keyed twice');

    expect($payment->fresh()->status)->toBe('voided');
});
