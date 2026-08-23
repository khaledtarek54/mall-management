<?php

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Notifications\PaymentReceivedNotification;
use App\Services\CreditNoteService;
use App\Services\Paymob\PaymobPaymentInitiator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| PAYMOB PAYMENTS — net-new scenarios.
|--------------------------------------------------------------------------
| Complements (does NOT duplicate):
|   - tests/Feature/Http/Paymob/CallbackControllerTest.php
|       happy capture, bad HMAC, unknown order, already-captured short-circuit,
|       success=false → failed, /paymob/return UX bounce.
|   - tests/Feature/Services/Paymob/PaymobPaymentInitiatorTest.php
|       initiated row + allocation, reuse window, fresh-after-window.
|   - tests/Feature/Scenarios/PaymentScenarioTest.php
|       allocation math, demo path settles+notifies, cross-tenant guard.
|
| GAPS this file closes, by case class:
|   STATE-TRANSITION — a voided success (success=true, is_voided=true) is NOT a
|                      capture: payment → failed, invoice balance stays open.
|   NEGATIVE/VALIDATION — a signed payload with a missing/zero order.id is a 422
|                      missing_order_id and creates/changes nothing.
|   IDEMPOTENCY      — replaying the SAME success callback twice settles the
|                      invoice exactly once (no double paid_amount, one captured
|                      row, one notification); a callback for an already-terminal
|                      (refunded) payment acks 200 already_processed untouched.
|   PERSISTENCE      — capture promotes gateway_transaction_id to the txn:order
|                      form and stores Paymob's obj in gateway_response.
|   SCOPING          — with two tenants each holding an initiated session, the
|                      callback for order A captures ONLY tenant A's invoice;
|                      tenant B's invoice + payment are untouched.
|   NOTIFICATION     — a successful capture fans the PaymentReceived notice out
|                      to BOTH the Tenant record AND each portal TenantUser
|                      (Tenant::notifyPortal); a failed capture notifies nobody.
|   DEMO FAN-OUT     — RecordDemoPaymentAction notifies the portal user too and
|                      stamps gateway='demo' + a generated RCT- reference.
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
    $this->invoice = makeInvoice($this->lease, ['total' => 1200, 'balance' => 1200]);
});

/**
 * Drive PaymobPaymentInitiator::start() with a faked Paymob API so we get a
 * real 'initiated' Payment keyed by the given order id — exactly what the
 * Pay-Now action would leave behind for the S2S callback to find.
 */
function scnInitiatedPayment(Invoice $invoice, int $orderId): Payment
{
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => $orderId]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    app(PaymobPaymentInitiator::class)->start($invoice);

    return Payment::where('gateway_transaction_id', "paymob:order:{$orderId}")->firstOrFail();
}

/** Build a Paymob S2S "transaction processed" payload. */
function scnCallbackPayload(int $orderId, int $txnId, bool $success = true, bool $voided = false): array
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
            'is_voided' => $voided,
            'order' => ['id' => $orderId],
            'owner' => 5,
            'pending' => false,
            'source_data' => ['pan' => '****', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => $success,
        ],
    ];
}

/** Sign a payload with Paymob's exact field-concatenation + HMAC-SHA512. */
function scnSign(array $payload, string $secret = 'TEST-HMAC-SECRET'): string
{
    $obj = $payload['obj'];
    $b = fn ($v) => $v ? 'true' : 'false';
    $fields = [
        $obj['amount_cents'], $obj['created_at'], $obj['currency'],
        $b($obj['error_occured']), $b($obj['has_parent_transaction']),
        $obj['id'], $obj['integration_id'],
        $b($obj['is_3d_secure']), $b($obj['is_auth']),
        $b($obj['is_capture']), $b($obj['is_refunded']),
        $b($obj['is_standalone_payment']), $b($obj['is_voided']),
        $obj['order']['id'], $obj['owner'], $b($obj['pending']),
        $obj['source_data']['pan'], $obj['source_data']['sub_type'], $obj['source_data']['type'],
        $b($obj['success']),
    ];

    return hash_hmac('sha512', implode('', $fields), $secret);
}

/** POST a signed payload to the S2S callback route. */
function scnPostCallback(array $payload)
{
    return test()->postJson(route('paymob.callback', ['hmac' => scnSign($payload)]), $payload);
}

// ============================================================
// STATE-TRANSITION — a voided success is NOT a capture
// ============================================================

it('treats a voided success (success=true, is_voided=true) as failed and leaves the invoice open', function () {
    Notification::fake();

    $payment = scnInitiatedPayment($this->invoice, orderId: 9101);
    $payload = scnCallbackPayload(orderId: 9101, txnId: 700001, success: true, voided: true);

    // The HMAC covers is_voided, so signing the voided payload is itself the
    // realistic case — Paymob would sign it with is_voided=true.
    scnPostCallback($payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'failed']);

    expect($payment->fresh()->status)->toBe('failed');

    // isCapture = success && !voided → false, so no allocation lands.
    $this->invoice->refresh();
    expect((float) $this->invoice->paid_amount)->toBe(0.0);
    expect((float) $this->invoice->balance)->toBe(1200.0);
    expect($this->invoice->status)->not->toBe('paid');

    Notification::assertNothingSent();
});

// ============================================================
// VALIDATION — a signed payload with no order id is a 422
// ============================================================

it('returns 422 missing_order_id for a signed payload whose order.id is zero', function () {
    // A well-signed callback that simply carries order.id = 0.
    $payload = scnCallbackPayload(orderId: 0, txnId: 700002);

    scnPostCallback($payload)
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'missing_order_id']);

    // Nothing was created or touched.
    expect(Payment::where('gateway', 'paymob')->count())->toBe(0);
});

// ============================================================
// IDEMPOTENCY — a true replay of the same success settles once
// ============================================================

it('settles the invoice exactly once when the same success callback is replayed', function () {
    Notification::fake();

    $payment = scnInitiatedPayment($this->invoice, orderId: 9102);
    $payload = scnCallbackPayload(orderId: 9102, txnId: 700003);

    // First delivery captures.
    scnPostCallback($payload)
        ->assertOk()
        ->assertJson(['status' => 'captured']);

    // Second, identical delivery: the controller FINDS the payment, sees it is already captured,
    // and declines to touch it.
    //
    // This used to assert `unknown_order`, and the comment described why as though it were the
    // design: capture promoted `gateway_transaction_id` from `paymob:order:{id}` to
    // `paymob:txn:{txn}:order:{id}`, so the lookup missed. Idempotency held by ACCIDENT — the
    // controller never reached its own decision. The same miss discarded a successful retry after a
    // decline, which was real money (2026-08-17, see PaymobRetryAfterDeclineTest). The property this
    // test is named for is unchanged; what it now proves is that the controller decides it.
    scnPostCallback($payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'skipped' => 'already_processed']);

    // Captured exactly once.
    expect(Payment::where('gateway', 'paymob')->where('status', 'captured')->count())->toBe(1);
    expect($payment->fresh()->status)->toBe('captured');

    // paid_amount did not double; balance is settled, not negative.
    $this->invoice->refresh();
    expect((float) $this->invoice->paid_amount)->toBe(1200.0);
    expect((float) $this->invoice->balance)->toBe(0.0);
    expect($this->invoice->status)->toBe('paid');

    // The tenant was notified exactly once (the replay produced no second copy).
    Notification::assertSentToTimes($this->tenant, PaymentReceivedNotification::class, 1);
});

it('acks 200 already_processed for a callback on an already-terminal (refunded) payment and changes nothing', function () {
    Notification::fake();

    $payment = scnInitiatedPayment($this->invoice, orderId: 9103);
    // Terminal state that still carries the bare-order anchor (e.g. a manual
    // refund recorded before any capture callback arrived).
    $payment->update(['status' => 'refunded']);

    $payload = scnCallbackPayload(orderId: 9103, txnId: 700004);

    scnPostCallback($payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'skipped' => 'already_processed']);

    // Status untouched, no allocation, no notification.
    expect($payment->fresh()->status)->toBe('refunded');
    expect($payment->fresh()->gateway_transaction_id)->toBe('paymob:order:9103');

    $this->invoice->refresh();
    expect((float) $this->invoice->paid_amount)->toBe(0.0);
    expect((float) $this->invoice->balance)->toBe(1200.0);

    Notification::assertNothingSent();
});

// ============================================================
// OVER-ALLOCATION — a credit applied after session init must not
// over-allocate the invoice when the (already-collected) card payment captures
// ============================================================

it('clamps a Paymob capture to the invoice balance when a credit was applied after session init', function () {
    Notification::fake();

    // Invoice 1200 → the initiated Paymob session allocates the full 1200.
    $payment = scnInitiatedPayment($this->invoice, orderId: 9401);
    expect((float) $payment->invoices()->first()->pivot->allocated_amount)->toBe(1200.0);

    // A 400 credit note is applied to the invoice BEFORE the callback arrives,
    // dropping the real balance to 800 (the card was already charged 1200).
    $note = CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'lease_id' => $this->lease->id,
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'reason' => 'adjustment',
        'subtotal' => 400, 'vat_amount' => 0, 'total' => 400,
        'applied_amount' => 0, 'balance' => 400, 'currency' => 'EGP',
    ]);
    app(CreditNoteService::class)->applyToInvoice($note, $this->invoice, 400);
    expect((float) $this->invoice->fresh()->balance)->toBe(800.0);

    // The S2S callback captures the already-collected 1200.
    scnPostCallback(scnCallbackPayload(orderId: 9401, txnId: 700009))
        ->assertOk()
        ->assertJson(['status' => 'captured']);

    $this->invoice->refresh();
    // Settled EXACTLY — paid_amount must not exceed total (no over-allocation).
    expect((float) $this->invoice->paid_amount)->toBe(1200.0);
    expect((float) $this->invoice->balance)->toBe(0.0);
    expect($this->invoice->status)->toBe('paid');

    // The allocation was clamped to what fit (800); the 400 excess stays unallocated
    // (a genuine overpayment → unearned), never over-allocating the invoice.
    expect((float) $payment->fresh()->invoices()->first()->pivot->allocated_amount)->toBe(800.0);
    expect(fn () => $payment->fresh()->assertInvoicesNotOverAllocated([$this->invoice->id]))
        ->not->toThrow(DomainException::class);
});

it('allocates nothing when the invoice was cancelled after session init', function () {
    Notification::fake();

    $payment = scnInitiatedPayment($this->invoice, orderId: 9402); // allocated 1200
    expect((float) $payment->invoices()->first()->pivot->allocated_amount)->toBe(1200.0);

    // The invoice is cancelled before the (already-collected) card payment captures.
    $this->invoice->update(['status' => 'cancelled']);
    $this->invoice->recomputeTotals();

    scnPostCallback(scnCallbackPayload(orderId: 9402, txnId: 700010))
        ->assertOk()
        ->assertJson(['status' => 'captured']);

    // A cancelled invoice holds no AR: the allocation is clamped to 0 (the whole
    // payment is a tenant overpayment → unearned), and the invoice stays at zero.
    expect((float) $payment->fresh()->invoices()->first()->pivot->allocated_amount)->toBe(0.0);
    $this->invoice->refresh();
    expect((float) $this->invoice->paid_amount)->toBe(0.0);
    expect((float) $this->invoice->balance)->toBe(0.0);
    expect($this->invoice->status)->toBe('cancelled');
});

// ============================================================
// PERSISTENCE — capture promotes the txn id + stores the obj
// ============================================================

it('promotes gateway_transaction_id and persists the Paymob obj into gateway_response on capture', function () {
    Notification::fake();

    $payment = scnInitiatedPayment($this->invoice, orderId: 9104);
    $payload = scnCallbackPayload(orderId: 9104, txnId: 700005);

    scnPostCallback($payload)->assertOk();

    $payment->refresh();
    // The bare-order anchor is promoted to the txn:order form.
    expect($payment->gateway_transaction_id)->toBe('paymob:txn:700005:order:9104');

    // The full Paymob transaction object is captured for audit.
    $stored = $payment->gateway_response;
    expect($stored)->toBeArray();
    expect($stored['id'])->toBe(700005);
    expect($stored['order']['id'])->toBe(9104);
    expect($stored['success'])->toBeTrue();
});

// ============================================================
// SCOPING — the callback settles only its own tenant's invoice
// ============================================================

it('captures only the targeted order and leaves a second tenants initiated payment untouched', function () {
    Notification::fake();

    // Tenant B — a wholly separate tenant/invoice with its own initiated session.
    $tenantB = makeTenant();
    $leaseB = makeLease(makeUnit(makeAsset()), $tenantB);
    $invoiceB = makeInvoice($leaseB, ['total' => 5000, 'balance' => 5000]);

    // Both sessions share ONE Http::fake — a second Http::fake() call would NOT
    // override the already-registered orders stub (Laravel keeps the first
    // match), so we hand out distinct order ids via a single sequence.
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::sequence()
            ->push(['id' => 9201])
            ->push(['id' => 9202]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    app(PaymobPaymentInitiator::class)->start($this->invoice);
    app(PaymobPaymentInitiator::class)->start($invoiceB);

    $payA = Payment::where('gateway_transaction_id', 'paymob:order:9201')->firstOrFail();
    $payB = Payment::where('gateway_transaction_id', 'paymob:order:9202')->firstOrFail();

    // Fire the callback for ORDER A only.
    scnPostCallback(scnCallbackPayload(orderId: 9201, txnId: 700006))->assertOk();

    // Tenant A captured + settled.
    expect($payA->fresh()->status)->toBe('captured');
    $this->invoice->refresh();
    expect((float) $this->invoice->balance)->toBe(0.0);
    expect($this->invoice->status)->toBe('paid');

    // Tenant B is entirely untouched — still initiated, invoice still open.
    expect($payB->fresh()->status)->toBe('initiated');
    expect($payB->fresh()->gateway_transaction_id)->toBe('paymob:order:9202');
    $invoiceB->refresh();
    expect((float) $invoiceB->paid_amount)->toBe(0.0);
    expect((float) $invoiceB->balance)->toBe(5000.0);

    // Only tenant A is notified; tenant B gets nothing.
    Notification::assertSentTo($this->tenant, PaymentReceivedNotification::class);
    Notification::assertNotSentTo($tenantB, PaymentReceivedNotification::class);
});

// ============================================================
// NOTIFICATION — capture fans out to Tenant + each portal user
// ============================================================

it('fans the payment-received notice out to BOTH the tenant record and each portal user on capture', function () {
    Notification::fake();

    // Two portal logins for this tenant (the web bell reads TenantUser rows).
    $userA = makeTenantUser($this->tenant, isAdmin: true);
    $userB = makeTenantUser($this->tenant, isAdmin: false);

    scnInitiatedPayment($this->invoice, orderId: 9301);
    scnPostCallback(scnCallbackPayload(orderId: 9301, txnId: 700007))->assertOk();

    // Tenant::notifyPortal notifies the Tenant model AND every TenantUser.
    Notification::assertSentTo($this->tenant, PaymentReceivedNotification::class);
    Notification::assertSentTo($userA, PaymentReceivedNotification::class);
    Notification::assertSentTo($userB, PaymentReceivedNotification::class);
});

it('does not notify any portal surface when the capture fails', function () {
    Notification::fake();

    $user = makeTenantUser($this->tenant, isAdmin: true);

    scnInitiatedPayment($this->invoice, orderId: 9302);
    scnPostCallback(scnCallbackPayload(orderId: 9302, txnId: 700008, success: false))->assertOk();

    Notification::assertNotSentTo($this->tenant, PaymentReceivedNotification::class);
    Notification::assertNotSentTo($user, PaymentReceivedNotification::class);
    Notification::assertNothingSent();
});

// ============================================================
// DEMO FAN-OUT — the demo capture path mirrors the callback
// ============================================================

it('demo capture stamps gateway=demo with a generated reference and fans out to the portal user', function () {
    Notification::fake();

    $user = makeTenantUser($this->tenant, isAdmin: true);

    $payment = app(RecordDemoPaymentAction::class)->handle($this->invoice);

    // Gateway tag + a real generated RCT- reference (booted creating hook). A payment is a
    // RECEIPT — Yardi's word — and `PAY` belongs to payroll (EG-10).
    expect($payment->gateway)->toBe('demo');
    expect($payment->status)->toBe('captured');
    expect($payment->reference)->toStartWith('RCT-');
    expect($payment->gateway_transaction_id)->toStartWith('demo:invoice:'.$this->invoice->id.':');

    // Settles the invoice…
    $this->invoice->refresh();
    expect((float) $this->invoice->balance)->toBe(0.0);
    expect($this->invoice->status)->toBe('paid');

    // …and notifies BOTH the tenant record and the portal user.
    Notification::assertSentTo($this->tenant, PaymentReceivedNotification::class);
    Notification::assertSentTo($user, PaymentReceivedNotification::class);
});
