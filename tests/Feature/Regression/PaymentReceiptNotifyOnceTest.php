<?php

use App\Models\Payment;
use App\Models\Tenant;
use App\Notifications\PaymentReceivedNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Regression: the manual Create/Edit payment path allocated invoices AFTER
 * save, so the receipt notification used to fire against stale/empty
 * allocations. The fix routes the notification through
 * Payment::notifyReceiptOnce(), which the Create/Edit after-hooks call once
 * the pivot is synced. It must:
 *   - send the PaymentReceivedNotification to the tenant exactly once when the
 *     payment is captured AND has at least one allocation,
 *   - be idempotent (a second call sends nothing — guarded by
 *     payments.receipt_notified_at),
 *   - send nothing when the payment has no allocations.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant);
});

/**
 * Build a captured payment WITHOUT triggering the saved() notification path,
 * mirroring the Create/Edit page: the record is persisted first and the pivot
 * is synced afterwards, then notifyReceiptOnce() is invoked by the after-hook.
 */
function capturedPaymentWithoutNotice(Tenant $tenant): Payment
{
    return Payment::withoutEvents(fn () => Payment::create([
        'reference' => 'PAY-TEST-'.uniqid(),
        'tenant_id' => $tenant->id,
        'amount' => 11400,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'captured',
        'payment_date' => now(),
    ]));
}

it('notifyReceiptOnce sends the receipt notification exactly once for a captured, allocated payment', function () {
    Notification::fake();

    $invoice = makeInvoice($this->lease, ['tenant_id' => $this->tenant->id]);

    // Create/Edit page order: persist captured payment, THEN allocate.
    $payment = capturedPaymentWithoutNotice($this->tenant);
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => 11400]]);

    $payment->notifyReceiptOnce();

    Notification::assertSentToTimes(
        $this->tenant,
        PaymentReceivedNotification::class,
        1,
    );

    Notification::assertSentTo(
        $this->tenant,
        PaymentReceivedNotification::class,
        fn (PaymentReceivedNotification $n) => $n->payment->id === $payment->id
    );

    expect($payment->fresh()->receipt_notified_at)->not->toBeNull();
});

it('notifyReceiptOnce is idempotent — a second call sends nothing', function () {
    Notification::fake();

    $invoice = makeInvoice($this->lease, ['tenant_id' => $this->tenant->id]);

    $payment = capturedPaymentWithoutNotice($this->tenant);
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => 11400]]);

    $payment->notifyReceiptOnce();
    $payment->notifyReceiptOnce(); // re-trigger (e.g. a subsequent Edit save)

    Notification::assertSentToTimes(
        $this->tenant,
        PaymentReceivedNotification::class,
        1,
    );
});

it('notifyReceiptOnce sends nothing for a captured payment with no allocations', function () {
    Notification::fake();

    $payment = capturedPaymentWithoutNotice($this->tenant);

    $payment->notifyReceiptOnce();

    Notification::assertNothingSent();
    expect($payment->fresh()->receipt_notified_at)->toBeNull();
});
