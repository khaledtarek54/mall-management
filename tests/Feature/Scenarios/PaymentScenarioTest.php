<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Notifications\PaymentReceivedNotification;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| PAYMENTS + ALLOCATION — net-new scenarios.
|--------------------------------------------------------------------------
| Exercises Payment ↔ Invoice allocation through the model layer of record:
|   - Invoice::recomputeTotals() — the single source of truth for AR. It sums
|     ONLY `captured` payment allocations, sets balance = max(0, total - paid)
|     (never negative), and auto-flips status issued ⇄ partially_paid ⇄ paid
|     ⇄ overdue without clobbering manual overrides.
|   - Payment::saved → recomputeAllocatedInvoices() — a status flip to
|     `captured` rolls forward to every allocated invoice and fires the tenant
|     PaymentReceivedNotification.
|   - RecordDemoPaymentAction — the production capture path (initiated →
|     attach full balance → captured), shared with the Paymob S2S callback.
|
| Case classes covered, each with a CONCRETE balance/status assertion:
|   HAPPY            — exact payment → paid (balance 0); demo path → paid.
|   STATE-TRANSITION — issued → partially_paid → paid as captures accumulate.
|   BOUNDARY         — overpayment (paid > total) clamps balance to 0, not
|                      negative, and status is paid; a 1-piastre underpay stays
|                      partially_paid; the exact-cent payment lands paid.
|   NEGATIVE/STATE   — a non-captured (initiated/failed) payment moves nothing;
|                      a manual `disputed`/`cancelled` override is NOT clobbered.
|   SCOPING          — a payment only ever touches its OWN allocated invoice;
|                      a sibling invoice of the SAME tenant is untouched; the
|                      model guard rejects allocating across tenants.
|
| NOTE on dates: makeInvoice()'s default due_date (2026-02-10) is in the past
| relative to the frozen test "today", so an UNPAID recompute auto-flips to
| `overdue`. Where we assert the clean issued→partially_paid→paid ladder we
| give the invoice a FUTURE due_date so an unpaid invoice stays `issued`.
*/

/**
 * Allocate `$amount` of a freshly-captured payment to `$invoice` for its
 * tenant, then let Invoice::recomputeTotals() (via Payment::saved) settle the
 * invoice. Returns the Payment. Mirrors the Create/Edit page + S2S callback:
 * create `initiated`, attach the pivot, then flip to `captured`.
 */
function capturePayment(Invoice $invoice, float $amount, array $attrs = []): Payment
{
    $payment = Payment::create(array_merge([
        'tenant_id' => $invoice->tenant_id,
        'amount' => $amount,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'initiated',
        'payment_date' => now(),
    ], $attrs));

    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);

    $payment->status = 'captured';
    $payment->save(); // Payment::saved → recomputeAllocatedInvoices()

    return $payment;
}

/** A lease + invoice whose due date is in the FUTURE (stays `issued` unpaid). */
function futureDueInvoice(array $attrs = []): Invoice
{
    $lease = makeLease(makeUnit(makeAsset()));

    return makeInvoice($lease, array_merge([
        'due_date' => now()->addDays(30),
        'total' => 11400,
        'paid_amount' => 0,
        'balance' => 11400,
        'status' => 'issued',
    ], $attrs));
}

// ============================================================
// HAPPY — exact payment settles the invoice to paid / balance 0
// ============================================================

it('settles an invoice to paid with a zero balance on an exact-amount capture', function () {
    $invoice = futureDueInvoice(['total' => 11400, 'balance' => 11400]);

    capturePayment($invoice, 11400.0);

    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(11400.0);
    expect((float) $invoice->balance)->toBe(0.0);
    expect($invoice->status)->toBe('paid');
});

// ============================================================
// STATE-TRANSITION — issued → partially_paid → paid as captures accrue
// ============================================================

it('walks issued → partially_paid → paid as captured payments accumulate', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000]);

    // Pre-condition: an unpaid, future-due invoice is `issued`.
    expect($invoice->status)->toBe('issued');

    // First partial capture (4,000 of 10,000) → partially_paid, balance 6,000.
    capturePayment($invoice, 4000.0);
    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(4000.0);
    expect((float) $invoice->balance)->toBe(6000.0);
    expect($invoice->status)->toBe('partially_paid');

    // Second capture closes the gap (6,000) → paid, balance 0.
    capturePayment($invoice, 6000.0);
    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(10000.0);
    expect((float) $invoice->balance)->toBe(0.0);
    expect($invoice->status)->toBe('paid');
});

it('leaves a single partial capture as partially_paid with the remaining balance', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000]);

    capturePayment($invoice, 2500.0);

    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(2500.0);
    expect((float) $invoice->balance)->toBe(7500.0);
    expect($invoice->status)->toBe('partially_paid');
});

// ============================================================
// BOUNDARY — overpayment, exact-cent, and 1-piastre underpay
// ============================================================

it('clamps an overpayment to a zero balance (never negative) and marks it paid', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000]);

    // Allocate MORE than the total.
    capturePayment($invoice, 12000.0);

    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(12000.0); // paid reflects the real allocation…
    expect((float) $invoice->balance)->toBe(0.0);          // …but balance floors at 0, not -2000.
    expect((float) $invoice->balance)->toBeGreaterThanOrEqual(0.0);
    expect($invoice->status)->toBe('paid');
});

it('treats an exact-to-the-cent capture as fully paid', function () {
    $invoice = futureDueInvoice(['total' => 11399.99, 'balance' => 11399.99]);

    capturePayment($invoice, 11399.99);

    $invoice->refresh();
    expect((float) $invoice->balance)->toBe(0.0);
    expect($invoice->status)->toBe('paid');
});

it('keeps a one-piastre underpayment as partially_paid (boundary just below total)', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000]);

    capturePayment($invoice, 9999.99);

    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(9999.99);
    expect((float) $invoice->balance)->toBe(0.01);
    expect($invoice->status)->toBe('partially_paid');
});

// ============================================================
// NEGATIVE — non-captured payments contribute nothing
// ============================================================

it('does not move the balance for an allocated payment that is still initiated (not captured)', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000]);

    // Create an allocation but leave the payment in `initiated` — never captured.
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => 5000,
        'currency' => 'EGP',
        'method' => 'card',
        'status' => 'initiated',
        'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);
    $payment->recomputeAllocatedInvoices();

    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(0.0);
    expect((float) $invoice->balance)->toBe(10000.0);
    expect($invoice->status)->toBe('issued'); // untouched, future-due
});

it('excludes a failed payment allocation from the paid amount', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000]);

    // A good capture brings it to partially_paid…
    capturePayment($invoice, 3000.0);
    expect($invoice->refresh()->status)->toBe('partially_paid');

    // …a SECOND allocation that ends up `failed` must NOT count.
    $failed = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => 7000,
        'currency' => 'EGP',
        'method' => 'card',
        'status' => 'failed',
        'payment_date' => now(),
    ]);
    $failed->invoices()->attach($invoice->id, ['allocated_amount' => 7000]);
    $failed->recomputeAllocatedInvoices();

    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(3000.0); // only the captured 3,000
    expect((float) $invoice->balance)->toBe(7000.0);
    expect($invoice->status)->toBe('partially_paid');
});

it('drops the paid amount back when a captured payment is reverted to failed', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000]);

    $payment = capturePayment($invoice, 10000.0);
    expect($invoice->refresh()->status)->toBe('paid');

    // Chargeback / reversal: flip the captured payment to failed.
    $payment->status = 'failed';
    $payment->save(); // Payment::saved → recompute

    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(0.0);
    expect((float) $invoice->balance)->toBe(10000.0);
    // Future-due, no captured payments → back to issued.
    expect($invoice->status)->toBe('issued');
});

// ============================================================
// STATE — manual status overrides are not clobbered by recompute
// ============================================================

it('does not auto-flip a manually disputed invoice when a capture arrives', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000, 'status' => 'disputed']);

    capturePayment($invoice, 10000.0);

    $invoice->refresh();
    // paid_amount / balance still recompute…
    expect((float) $invoice->paid_amount)->toBe(10000.0);
    expect((float) $invoice->balance)->toBe(0.0);
    // …but the manual `disputed` override is preserved (not flipped to paid).
    expect($invoice->status)->toBe('disputed');
});

it('does not auto-flip a cancelled invoice on recompute', function () {
    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000, 'status' => 'cancelled']);

    capturePayment($invoice, 4000.0);

    $invoice->refresh();
    expect($invoice->status)->toBe('cancelled');
    expect((float) $invoice->paid_amount)->toBe(4000.0);
});

// ============================================================
// SCOPING — a payment only ever touches its own allocated invoice
// ============================================================

it('only settles the allocated invoice, leaving a same-tenant sibling untouched', function () {
    // Two invoices for the SAME tenant on the same lease.
    $lease = makeLease(makeUnit(makeAsset()));
    $tenantId = $lease->tenant_id;

    $target = makeInvoice($lease, [
        'due_date' => now()->addDays(30),
        'total' => 5000, 'paid_amount' => 0, 'balance' => 5000, 'status' => 'issued',
    ]);
    $sibling = makeInvoice($lease, [
        'due_date' => now()->addDays(30),
        'total' => 8000, 'paid_amount' => 0, 'balance' => 8000, 'status' => 'issued',
    ]);

    expect($target->tenant_id)->toBe($tenantId)
        ->and($sibling->tenant_id)->toBe($tenantId);

    // Pay the target in full.
    capturePayment($target, 5000.0);

    $target->refresh();
    $sibling->refresh();

    // Target settled…
    expect((float) $target->balance)->toBe(0.0);
    expect($target->status)->toBe('paid');
    // …sibling for the SAME tenant is completely untouched.
    expect((float) $sibling->paid_amount)->toBe(0.0);
    expect((float) $sibling->balance)->toBe(8000.0);
    expect($sibling->status)->toBe('issued');
});

it('rejects allocating a payment to an invoice belonging to a different tenant', function () {
    $invoiceA = futureDueInvoice(['total' => 5000, 'balance' => 5000]);

    // A second, different tenant's invoice.
    $otherLease = makeLease(makeUnit(makeAsset()));
    $invoiceB = makeInvoice($otherLease, [
        'due_date' => now()->addDays(30),
        'total' => 5000, 'paid_amount' => 0, 'balance' => 5000, 'status' => 'issued',
    ]);

    expect($invoiceA->tenant_id)->not->toBe($invoiceB->tenant_id);

    // A payment owned by tenant A.
    $payment = Payment::create([
        'tenant_id' => $invoiceA->tenant_id,
        'amount' => 5000,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'captured',
        'payment_date' => now(),
    ]);

    // The model guard must reject the cross-tenant allocation.
    expect(fn () => $payment->assertInvoicesShareTenant([$invoiceB->id]))
        ->toThrow(\DomainException::class);

    // The same-tenant invoice passes the guard.
    expect(fn () => $payment->assertInvoicesShareTenant([$invoiceA->id]))
        ->not->toThrow(\DomainException::class);
});

// ============================================================
// DEMO PATH — RecordDemoPaymentAction mirrors a real capture
// ============================================================

it('settles an invoice via the demo capture path and notifies the tenant', function () {
    Notification::fake();

    $invoice = futureDueInvoice(['total' => 11400, 'balance' => 11400]);

    $payment = app(\App\Actions\Api\V1\Payments\RecordDemoPaymentAction::class)->handle($invoice);

    $invoice->refresh();
    expect($payment->status)->toBe('captured');
    expect((float) $payment->amount)->toBe(11400.0);
    expect((float) $invoice->balance)->toBe(0.0);
    expect($invoice->status)->toBe('paid');

    // The captured transition fires the tenant payment-received notification.
    Notification::assertSentTo($invoice->tenant, PaymentReceivedNotification::class);
});

it('records the demo payment for only the remaining balance on a partially paid invoice', function () {
    Notification::fake();

    $invoice = futureDueInvoice(['total' => 10000, 'balance' => 10000]);

    // Pay half through the normal path first.
    capturePayment($invoice, 6000.0);
    $invoice->refresh();
    expect((float) $invoice->balance)->toBe(4000.0);

    // The demo action pays the REMAINING balance only.
    $payment = app(\App\Actions\Api\V1\Payments\RecordDemoPaymentAction::class)->handle($invoice);

    $invoice->refresh();
    expect((float) $payment->amount)->toBe(4000.0);
    expect((float) $invoice->paid_amount)->toBe(10000.0);
    expect((float) $invoice->balance)->toBe(0.0);
    expect($invoice->status)->toBe('paid');
});
