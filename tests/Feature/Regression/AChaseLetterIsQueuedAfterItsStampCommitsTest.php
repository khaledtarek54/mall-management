<?php

use App\Models\Invoice;
use App\Notifications\InvoiceOverdueTenantNotification;
use App\Notifications\LateFeeAppliedNotification;
use App\Notifications\LeaseExpiryApproachingNotification;
use App\Services\LateFeeService;
use App\Settings\BillingSettings;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Regression — SW-248. A tenant-facing notice is dispatched AFTER the stamp that says it was sent
 * has committed, never inside the transaction that writes it.
 *
 * Three sites called `Tenant::notifyPortal()` inside `DB::transaction()`, under `lockForUpdate()`:
 * `RemindOverdueTenantsCommand` (the dunning ladder) and `RemindExpiringLeasesCommand` (the
 * renewal reminder) BEFORE writing their idempotency stamp, and `LateFeeService::applyTo()` after
 * the fee was saved but before it was committed. All three notifications are `ShouldQueue`, and
 * each carried a docblock saying the in-transaction dispatch was safe BECAUSE it was queued — "a
 * delivery failure can no longer roll back the stamp".
 *
 * That was true on exactly one queue driver. On `database` the job is a row on the same connection
 * and commits with the stamp; on redis — what the box runs, with `after_commit` false on the
 * connections that carry the key — the push leaves at the `notifyPortal()` call, and a rollback
 * after it (a deadlock, a failed save) leaves the chase letter in flight with no stamp to say so.
 * The next run chases the tenant again. For the late fee the job named a fee that was never
 * written: the worker restores it with `firstOrFail()` and the job FAILS in Horizon — not a mail
 * to the tenant, but not a thing a queue should carry either. Stamp first, `DB::afterCommit()`
 * second — the SW-213 shape the rest of the scans already take — is right on every driver, and
 * the trade it makes is the one SW-213 made: a push failure after the commit is one logged miss
 * with the stamp standing, never a duplicate. Logged to the OPS LOG by record (through
 * `BestEffortNotification`), because a scheduled command's own output is `/dev/null` on the box —
 * and for the late fee that is also what keeps a fee that STANDS from being reported as FAILED.
 *
 * Teeth per site: (a) depth — bites at all three; (b) the claim is on the row first — bites for
 * the two commands; for the late fee a same-connection read sees the uncommitted fee row, so (b)
 * is a control there and (a) is the tooth.
 *
 * Nothing here could be seen by `NotificationsAreNotSentUnderARowLockConformanceTest` until it
 * learned to derive delivery WRAPPERS: it matched `->notify(` and these call `notifyPortal(`.
 *
 * The technique is `AnSlaAlertIsMailedAfterTheLockIsReleasedTest`'s: `QUEUE_CONNECTION=sync` in
 * phpunit.xml sends a queued notification inline, so `NotificationSending` fires where the dispatch
 * happens, and `DB::transactionLevel()` there is the only observable proxy for the lock on sqlite.
 * `RefreshDatabase` sits at depth 1; 2 means "inside the command's own transaction".
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'CHS']);
    $this->lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
    makeTenantUser($this->tenant);

    $this->sends = collect();

    // NOT `Notification::fake()` — the fake replaces the dispatcher and `NotificationSending`
    // never fires. `MAIL_MAILER=array` keeps the mail channel off the network.
    Event::listen(NotificationSending::class, function (NotificationSending $event) {
        $claimed = match (true) {
            $event->notification instanceof InvoiceOverdueTenantNotification => DB::table('invoices')
                ->where('id', $event->notification->invoice->id)
                ->whereNotNull('tenant_overdue_notified_at')
                ->exists(),
            $event->notification instanceof LeaseExpiryApproachingNotification => DB::table('leases')
                ->where('id', $event->notification->lease->id)
                ->whereNotNull('expiry_reminder_notified_at')
                ->exists(),
            // The FEE invoice is the claim here — it is the row the transaction raises.
            $event->notification instanceof LateFeeAppliedNotification => DB::table('invoices')
                ->where('id', $event->notification->feeInvoice->id)
                ->exists(),
            default => null,
        };

        if ($claimed === null) {
            return;
        }

        $this->sends->push([
            'class' => $event->notification::class,
            'depth' => DB::transactionLevel(),
            // Read straight off the table: the model layer would answer from the copy in memory.
            'claimed' => $claimed,
        ]);
    });
});

function assertQueuedAfterTheCommit(string $class): void
{
    $sends = test()->sends->where('class', $class);

    // The premise — without it every assertion below passes over an empty collection.
    expect($sends->count())->toBeGreaterThan(0, "no {$class} was dispatched — the assertions below would pass over nothing");

    // (a) never inside the command's own transaction.
    expect($sends->pluck('depth')->unique()->values()->all())->toBe([1], "{$class} was dispatched inside the transaction");

    // (b) and the row was claimed first.
    expect($sends->pluck('claimed')->unique()->values()->all())->toBe([true], "{$class} was dispatched before its stamp was on the row");
}

it('stamps the invoice and commits before it queues the chase letter', function () {
    makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => now()->subDays(40)->toDateString(),
        'due_date' => now()->subDays(30)->toDateString(),
    ]);

    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();

    assertQueuedAfterTheCommit(InvoiceOverdueTenantNotification::class);
});

it('stamps the lease and commits before it queues the expiry reminder', function () {
    $this->lease->forceFill(['expiry_date' => now()->addDays(30)])->save();

    $this->artisan('leases:remind-expiring')->assertSuccessful();

    assertQueuedAfterTheCommit(LeaseExpiryApproachingNotification::class);
});

it('raises the late fee and commits before it tells the tenant', function () {
    $settings = app(BillingSettings::class);
    $settings->late_fee_percent = 5;
    $settings->late_fee_grace_days = 7;

    $invoice = makeInvoice($this->lease, [
        'due_date' => '2026-01-01',
        'status' => 'overdue',
        'balance' => 10000,
    ]);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeTrue();

    assertQueuedAfterTheCommit(LateFeeAppliedNotification::class);

    // The fee is on the books — a bell after the commit is a bell about something real.
    expect(Invoice::whereKey($invoice->id)->value('late_fee_invoice_id'))->not->toBeNull();
});

it('still chases exactly once — deferring the send did not cost the stamp', function () {
    // The CONTROL for the reordering: a claim written before delivery is only correct if it is
    // still a claim, so a second run must find the stamp and say nothing.
    makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => now()->subDays(40)->toDateString(),
        'due_date' => now()->subDays(30)->toDateString(),
    ]);

    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();
    $first = $this->sends->count();

    expect($first)->toBeGreaterThan(0);

    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();

    expect($this->sends->count())->toBe($first, 'the second run re-chased a tenant already chased');
});
