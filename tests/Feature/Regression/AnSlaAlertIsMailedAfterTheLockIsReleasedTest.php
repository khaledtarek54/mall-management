<?php

use App\Models\FacilityWorkOrder;
use App\Notifications\WorkOrderResponseSlaBreachedNotification;
use App\Notifications\WorkOrderSlaBreachedNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Regression — SW-213. A scheduled scan must not hold a row lock across a mail send.
 *
 * `ScanWorkOrderSlaBreachesCommand::alertBreach()` locked the work order with `lockForUpdate()`
 * and then called `Notification::send()` INSIDE the transaction. Measured on the shipped classes:
 * 3 of the 38 notifications in `app/Notifications` implement `ShouldQueue`, and neither of this
 * command's two is one of them, while both declare `via() = ['mail', 'database']`. So the X lock on
 * `facility_work_orders` was held across one synchronous MailerSend round-trip PER RECIPIENT, every
 * hour, and every operator saving that job queued behind it.
 *
 * The same shape was in eight other places — `ScanTenantRequestSlaBreachesCommand`,
 * `ScanOverdueInvoicesCommand` (which locks `invoices`, the most contended table in the system),
 * `ScanLeaseOptionWindowsCommand`, `ScanContractRenewalsCommand`, both document-expiry scans and
 * `ScanLowStockCommand`. `NotificationsAreNotSentUnderARowLockConformanceTest` is what keeps the
 * whole class closed; this file proves the mechanism actually defers.
 *
 * TWO INDEPENDENT TEETH, because moving the stamp and deferring the send are two different edits
 * and only one mutation is caught by each:
 *
 *   (a) the send happens at transaction depth 1, not 2. `RefreshDatabase` already sits at depth 1,
 *       so 2 means "still inside the scan's own transaction". SQLite compiles `lockForUpdate()` to
 *       nothing, so the depth is the only observable proxy for the lock in this suite — and it is
 *       the right one: in production the scan's transaction is the OUTERMOST, and committing it is
 *       exactly what releases the locks. Proven empirically before this test was written: inside
 *       the closure `transactionLevel()` reads 2, inside the `DB::afterCommit()` callback it reads
 *       1, because `Connection::commit()` decrements before it runs the callbacks.
 *
 *   (b) the claim is already ON the row when the send runs — which is what makes the reordering
 *       safe rather than merely faster. It also ends a duplicate the transaction was CAUSING: mail
 *       is not transactional, so a throw on the third recipient used to roll back the first two
 *       recipients' bell rows and the stamp while their e-mails had already left, and the next
 *       hourly run mailed them again, for as long as the third address kept failing.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'LOK']);

    // `AssetStaffRecipients` always unions in every super_admin, so one user is a recipient of
    // both alerts without any property assignment.
    makeUser('super_admin');

    $this->sends = collect();

    // NOT `Notification::fake()`: the fake replaces the dispatcher, so `NotificationSending` never
    // fires and there is nothing to read a depth from. `MAIL_MAILER=array` in phpunit.xml already
    // keeps the mail channel off the network.
    Event::listen(NotificationSending::class, function (NotificationSending $event) {
        $stampColumn = match (true) {
            $event->notification instanceof WorkOrderSlaBreachedNotification => 'sla_breach_notified_at',
            $event->notification instanceof WorkOrderResponseSlaBreachedNotification => 'response_breach_notified_at',
            default => null,
        };

        if ($stampColumn === null) {
            return;
        }

        $this->sends->push([
            'class' => $event->notification::class,
            'depth' => DB::transactionLevel(),
            // Read straight off the table: the model layer would answer from the copy in memory.
            'claimed' => DB::table('facility_work_orders')
                ->where('id', $event->notification->order->id)
                ->whereNotNull($stampColumn)
                ->exists(),
        ]);
    });
});

it('claims the work order and commits before it mails the breach', function () {
    // One corrective job past BOTH clocks and never acknowledged, so a single run drives both
    // alert paths — `alertBreach()` and `alertResponseBreach()` — which is what makes this one
    // fixture cover the two places the defect lived in this file.
    $order = correctiveOrder([
        'status' => 'open',
        'sla_clock' => FacilityWorkOrder::SLA_CLOCK_CALENDAR,
        'target_response_at' => now()->subHours(8),
        'target_resolution_at' => now()->subHours(6),
    ]);

    $this->artisan('facility:scan-sla-breaches')->assertExitCode(0);

    // The premise. Without it every assertion below passes over an empty collection.
    expect($this->sends->pluck('class')->unique()->values()->all())
        ->toEqualCanonicalizing([
            WorkOrderSlaBreachedNotification::class,
            WorkOrderResponseSlaBreachedNotification::class,
        ]);

    // (a) never under the scan's own transaction.
    expect($this->sends->pluck('depth')->unique()->values()->all())->toBe([1]);

    // (b) and the row was claimed first.
    expect($this->sends->pluck('claimed')->unique()->values()->all())->toBe([true]);

    $order->refresh();

    expect($order->sla_breach_notified_at)->not->toBeNull()
        ->and($order->response_breach_notified_at)->not->toBeNull();
});

it('still alerts exactly once — deferring the send did not cost the idempotency stamp', function () {
    // The CONTROL for the reordering. A claim written before delivery is only correct if it is
    // still a claim: a second run must find the stamp and say nothing.
    correctiveOrder([
        'status' => 'open',
        'sla_clock' => FacilityWorkOrder::SLA_CLOCK_CALENDAR,
        'target_response_at' => now()->subHours(8),
        'target_resolution_at' => now()->subHours(6),
    ]);

    $this->artisan('facility:scan-sla-breaches')->assertExitCode(0);

    $first = $this->sends->count();

    expect($first)->toBeGreaterThan(0, 'the first run alerted nobody — the second-run assertion would pass over nothing');

    $this->artisan('facility:scan-sla-breaches')->assertExitCode(0);

    expect($this->sends->count())->toBe($first, 'the second run re-alerted a job already alerted');
});
