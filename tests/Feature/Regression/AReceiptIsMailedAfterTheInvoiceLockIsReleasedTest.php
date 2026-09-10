<?php

use App\Models\Payment;
use App\Notifications\PaymentReceivedNotification;
use App\Services\CapturePaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Regression — the "payment received" mail goes out after the invoice lock is released.
 *
 * `Payment::booted()`'s `saved` hook called `notifyReceiptOnce()` inline, and that hook fires
 * inside `CapturePaymentService::capture()` and the Paymob callback's transaction — both holding
 * `lockForUpdate()` on the INVOICES (`lockInvoicesThenSelf`), the most contended table in the
 * system — while `PaymentReceivedNotification` is not `ShouldQueue` and its `via()` is
 * `['mail', 'database', 'push']`. So the mail went out per portal user with those locks held:
 * SW-213's exact shape, through a MODEL EVENT, which is a door no transaction-closure scan can see
 * — `NotificationsAreNotSentUnderARowLockConformanceTest` derives `notifyPortal` now and still
 * cannot see this one, because the call sits in `booted()` and not in a `DB::transaction(` body.
 * Found by the adversarial review of SW-248, 2026-09-10.
 *
 * `CreatePayment`/`EditPayment` already deferred THEIR call with `DB::afterCommit()` for exactly
 * this reason; the hook now does the same, and with no transaction open `afterCommit()` runs at
 * once, so the paths that never held a lock are unchanged.
 *
 * Same technique as `AnSlaAlertIsMailedAfterTheLockIsReleasedTest`: `DB::transactionLevel()` at
 * `NotificationSending` is the only observable proxy for the lock on sqlite. `RefreshDatabase`
 * sits at depth 1; 2 means "inside the capture's own transaction".
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->lease = makeLease(makeUnit(makeAsset(['code' => 'RCL'])), null, ['status' => 'active']);
    makeTenantUser($this->lease->tenant);

    $this->sends = collect();

    Event::listen(NotificationSending::class, function (NotificationSending $event) {
        if (! $event->notification instanceof PaymentReceivedNotification) {
            return;
        }

        $this->sends->push([
            'depth' => DB::transactionLevel(),
            // Read straight off the table: the capture must have COMMITTED before the send.
            'captured' => DB::table('payments')
                ->where('id', $event->notification->payment->id)
                ->where('status', 'captured')
                ->exists(),
        ]);
    });
});

it('captures, commits, and only then tells the tenant', function () {
    $invoice = makeInvoice($this->lease);

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'payment_date' => now()->toDateString(),
        'amount' => (float) $invoice->total,
        'method' => 'bank_transfer',
        'status' => 'initiated',
        'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $invoice->total]);

    app(CapturePaymentService::class)->capture($payment);

    // The premise — without it every assertion below passes over an empty collection.
    expect($this->sends->count())->toBeGreaterThan(0, 'no receipt notification was sent — the assertions below would pass over nothing');

    // (a) never inside the capture's transaction — the invoice lock has been released.
    expect($this->sends->pluck('depth')->unique()->values()->all())->toBe([1]);

    // (b) and the capture was already on the row.
    expect($this->sends->pluck('captured')->unique()->values()->all())->toBe([true]);

    expect($payment->fresh()->receipt_notified_at)->not->toBeNull();
});

it('still tells the tenant exactly once — the stamp survives the deferral', function () {
    $invoice = makeInvoice($this->lease);
    $payment = settleInvoiceInFull($invoice);

    // `settleInvoiceInFull()` attaches the allocation AFTER the captured receipt is created, so
    // the hook found no invoices and sent nothing — the shape the Create/Edit pages then close by
    // calling `notifyReceiptOnce()` themselves, outside any transaction.
    $payment->notifyReceiptOnce();
    $first = $this->sends->count();
    expect($first)->toBeGreaterThan(0);

    // A later save re-enters the hook; the stamp must make it a no-op.
    $payment->forceFill(['notes' => 'touched'])->save();

    expect($this->sends->count())->toBe($first);
});
