<?php

use App\Models\SystemSetting;
use App\Notifications\BooksDriftDetectedNotification;
use App\Support\Health;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * When the books stop agreeing with themselves, somebody has to find out.
 *
 * `accounting:sync-ledger` computed the GL↔AR and GL↔AP tie-out on every run and printed it with
 * `warn()`. The sweep runs on cron, so that went to `/dev/null`.
 *
 * The obvious objection — "but there is already a ledger alert" — is exactly why this was invisible.
 * `LedgerSyncFailedNotification` fires from `recordAndAlertFailures()`, which **returns early when
 * `$failed === 0`**, and a ledger that is drifting while posting every document cleanly has zero
 * failures by definition. The two persisted keys are both about documents that threw. So the one
 * number that says "the books no longer agree" reached no channel at all: not the console (cron),
 * not the bell, not the health endpoint, not a stored value anyone could query.
 *
 * And `billing:reconcile` — the deep re-derivation that says WHICH document disagrees — appeared
 * nowhere in `routes/console.php`. It existed, it worked, and only a diligent operator opening the
 * month-end checklist ever ran it.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);
    app(\App\Services\Accounting\FiscalCalendar::class)->ensureYear((int) now()->year);

    // The tie-out is deliberately skipped until something has actually POSTED — there is nothing to
    // tie out on an empty ledger, and raising a false failure there would be its own bug. So the
    // books need one real document before any of this is meaningful.
    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant());
    $this->invoice = makeInvoice($lease, [
        'status' => 'issued',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'paid_amount' => 0, 'balance' => 10000,
    ]);
});

it('records the tie-out on every sweep, so the number outlives the console', function () {
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // A stamp exists even when everything ties — "checked and fine" and "never checked" are
    // different states, and the health check has to tell them apart.
    expect(SystemSetting::get('ledger_tie_out_checked_at'))->not->toBeNull()
        ->and(SystemSetting::get('ledger_tie_out_ar_delta'))->not->toBeNull();
});

it('reports healthy books through the health endpoint', function () {
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $check = Health::run()['checks']['books_tie_out'];

    expect($check['ok'])->toBeTrue();
});

it('fails the health check once a delta is recorded', function () {
    // The state an uptime monitor must be able to see without anyone opening /admin.
    SystemSetting::put('ledger_tie_out_checked_at', now()->toIso8601String());
    SystemSetting::put('ledger_tie_out_ar_delta', '120000');
    SystemSetting::put('ledger_tie_out_ap_delta', '0');

    $check = Health::run()['checks']['books_tie_out'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('AR off by')
        ->and($check['detail'])->toContain('billing:reconcile');
});

it('surfaces a standing un-postable document, which alerted once and then went quiet', function () {
    // The third channel gap in the same finding. `recordAndAlertFailures()` de-dupes on a CHANGE in
    // the count, so a failure sitting at 3 for a month alerts once — and after that the only place
    // it exists is `PostsToLedger`'s banner on report pages nobody has open. Same number, no poller.
    SystemSetting::put('ledger_last_sync_failures', '3');

    $check = Health::run()['checks']['books_tie_out'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('3 document(s) could not post');
});

it('does not cry wolf before the sweep has ever run', function () {
    // A MISSING stamp is not drift — it means the sweep has not run, which the `scheduler` check
    // already reports. Failing here too would give the operator two alarms for one cause and teach
    // them to ignore this one.
    $check = Health::run()['checks']['books_tie_out'];

    expect($check['ok'])->toBeTrue()
        ->and($check['detail'])->toContain('not computed yet');
});

/**
 * Desync the sub-ledger from the GL the way a real bug would.
 *
 * `InvoiceJournalizer` posts Dr AR / Cr Revenue from the invoice TOTAL, and the tie-out compares
 * that against the sum of invoice BALANCES. So a balance that no longer reflects its settlements —
 * the exact failure mode `recomputeTotals()` exists to prevent, and the one a fifth settlement
 * channel would introduce — drifts the books while every document still posts cleanly. `saveQuietly`
 * because the point is that nothing observed the change.
 */
function driftTheBooks(float $to): void
{
    $invoice = test()->invoice->fresh();
    $invoice->balance = $to;
    $invoice->saveQuietly();
}

it('alerts the people who manage the books when drift STARTS', function () {
    // Driven through the real command, not by constructing the notification: the finding was that
    // nothing ever CALLED this, so a test that sends it by hand would pass against the old code.
    $accountant = makeUser('super_admin');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    Notification::fake();
    driftTheBooks(4000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    Notification::assertSentTo($accountant, BooksDriftDetectedNotification::class,
        fn (BooksDriftDetectedNotification $n) => abs($n->arDelta - 6000) < 0.01);

    expect((bool) SystemSetting::get('ledger_books_drifting'))->toBeTrue()
        ->and((float) SystemSetting::get('ledger_tie_out_ar_delta'))->toBe(6000.0);
});

it('does not re-alert every night while a known delta stands', function () {
    // The paired control. A nightly message repeating a delta somebody is already working on is a
    // message people filter — and the day it means something new, they filter that too.
    makeUser('super_admin');
    driftTheBooks(4000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    Notification::fake();
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    Notification::assertNothingSent();

    // Still drifting, still recorded — silence here is "already told you", not "resolved".
    expect((bool) SystemSetting::get('ledger_books_drifting'))->toBeTrue();
});

it('reaches mail as well as the bell', function () {
    // The accountant who needs this may not open /admin for days, and every day it goes unseen the
    // two sides drift further apart. Same reasoning as the sync-failure alert.
    $via = (new BooksDriftDetectedNotification(1, 0))->via(makeUser('super_admin'));

    expect($via)->toContain('mail')->toContain('database');
});

it('clears the drift flag when the books come back into line', function () {
    // Unlike the failures counter — which a windowed run must NOT clear, because it cannot re-verify
    // a document outside its window — the tie-out is whole-ledger on every run. There is no partial
    // view here to false-clear from, so a recovered book must stop alarming.
    driftTheBooks(4000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
    expect((bool) SystemSetting::get('ledger_books_drifting'))->toBeTrue();

    driftTheBooks(10000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect((bool) SystemSetting::get('ledger_books_drifting'))->toBeFalse()
        ->and((float) SystemSetting::get('ledger_tie_out_ar_delta'))->toBe(0.0);
});

it('schedules the deep reconciliation that says WHICH document disagrees', function () {
    // It existed and was never scheduled — the whole second half of this finding. Asserted against
    // the real schedule rather than the file's text, so renaming the command cannot leave this
    // green.
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command ?? '')
        ->filter(fn (string $c): bool => str_contains($c, 'billing:reconcile'));

    expect($events)->not->toBeEmpty();
});
