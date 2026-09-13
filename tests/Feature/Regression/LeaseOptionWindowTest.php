<?php

use App\Models\Lease;
use App\Models\LeaseOption;
use App\Notifications\LeaseOptionWindowNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;

/**
 * Lease options and their notice windows (OP-01/OP-02).
 *
 * **The gap this closes, stated as a test.** Atriom's only lease-date alert fired 90 days before
 * EXPIRY. A typical renewal clause reads "notice no earlier than 12 and no later than 9 months
 * before expiry" — so that reminder arrived three to six months AFTER the right had already been
 * lost. The system reliably spoke too late to act, which is worse than not speaking: it feels like
 * coverage.
 *
 * So the assertion that matters is not "an alert is sent" but **when**: before the window opens,
 * before it closes, and — only then — that a missed one is recorded as lapsed rather than sitting
 * open forever.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    // A manager assigned to the property is who AssetStaffRecipients resolves to.
    $this->manager = makeUser('manager', [$this->asset->id]);
    Notification::fake();
});

function optionLease(array $attrs = []): Lease
{
    return makeLease(makeUnit(test()->asset), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
    ], $attrs));
}

function optionOn(Lease $lease, array $attrs = []): LeaseOption
{
    return LeaseOption::create(array_merge([
        'lease_id' => $lease->id,
        'type' => 'renewal',
        'status' => 'open',
        'earliest_notice_date' => '2030-01-01',
        'latest_notice_date' => '2030-04-01',
        'term_months' => 60,
        'rent_basis' => 'uplift_percent',
        'uplift_percent' => 10,
    ], $attrs));
}

/* ---- the timing, which is the whole point ---------------------------------- */

it('alerts BEFORE the notice window opens, not after the lease expires', function () {
    // 20 days before the window opens, with a 30-day lead. The old expiry reminder would not have
    // spoken until 2030-10-02 — six months after this option was already dead.
    CarbonImmutable::setTestNow('2029-12-12');
    $option = optionOn(optionLease());

    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'opening' && $n->option->is($option));

    expect($option->fresh()->opening_notified_at)->not->toBeNull();
});

it('warns again as the deadline closes in', function () {
    CarbonImmutable::setTestNow('2030-03-20'); // 12 days before 2030-04-01
    $option = optionOn(optionLease(), ['opening_notified_at' => now()->subMonths(3)]);

    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'closing');

    expect($option->fresh()->closing_notified_at)->not->toBeNull();
});

it('does not tell the team to START after it has told them to DECIDE (SW-257)', function () {
    // An option first seen already inside its closing lead: a seeded or migrated lease, exactly the
    // staging soak's renewal (window 1–25 Sep, seeded 5 Sep). Day one says "decide"…
    CarbonImmutable::setTestNow('2026-09-06');
    $option = optionOn(optionLease(['commencement_date' => '2025-09-15', 'expiry_date' => '2026-12-31']), [
        'earliest_notice_date' => '2026-09-01',
        'latest_notice_date' => '2026-09-25',
    ]);

    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'closing' && $n->option->is($option));
    expect($option->fresh()->closing_notified_at)->not->toBeNull();

    // …and day two must NOT follow it with "you may now start the conversation". Before SW-257 it
    // did, because only the event sent was stamped and the opening branch never asked whether a
    // closing had gone.
    CarbonImmutable::setTestNow('2026-09-07');
    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    Notification::assertNotSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'opening');
    Notification::assertSentTimes(LeaseOptionWindowNotification::class, 1);

    // The stamp is not back-filled: no opening alert went, and the row says so.
    expect($option->fresh()->opening_notified_at)->toBeNull();

    // The only alert this option ever gets must therefore name the WHOLE window, not just the
    // deadline — the review found the closing body said "by :deadline" alone, so a reader who
    // served notice before the window opened would be refused with nothing to explain why.
    foreach (['en', 'ar'] as $locale) {
        App::setLocale($locale);
        $body = (new LeaseOptionWindowNotification($option->fresh(), 'closing'))->toDatabase($this->manager)['body'];
        expect($body)->toContain('2026-09-01')->toContain('2026-09-25');
    }
    App::setLocale('en');
});

it('still announces the opening first on an option seen in good time — the control for SW-257', function () {
    // Seen twenty days before the window opens: opening now, closing later, in that order.
    CarbonImmutable::setTestNow('2029-12-12');
    $option = optionOn(optionLease());

    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'opening' && $n->option->is($option));

    CarbonImmutable::setTestNow('2030-03-20'); // inside the closing lead
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'closing' && $n->option->is($option));

    expect($option->fresh()->opening_notified_at)->not->toBeNull()
        ->and($option->fresh()->closing_notified_at)->not->toBeNull();
});

it('re-arms the deadline alert when the deadline is extended (SW-258)', function () {
    // "The deadline is near" went out about 1 April. Then the landlord grants an extension to
    // 1 June. Nothing cleared the stamp, so the option was silent until it lapsed in June.
    CarbonImmutable::setTestNow('2030-03-20');
    $option = optionOn(optionLease(), ['opening_notified_at' => now()->subMonths(3)]);
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    expect($option->fresh()->closing_notified_at)->not->toBeNull();

    $option->fresh()->update(['latest_notice_date' => '2030-06-01']);
    expect($option->fresh()->closing_notified_at)->toBeNull();

    // Inside the lead of the NEW deadline the closing is announced again — about the new date.
    CarbonImmutable::setTestNow('2030-05-10');
    Notification::fake();
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'closing' && $n->option->is($option)
            && str_contains($n->toDatabase($this->manager)['body'], '2030-06-01'));
});

it('re-arms the opening alert when the window\'s start is moved (SW-258)', function () {
    CarbonImmutable::setTestNow('2029-12-12');
    $option = optionOn(optionLease());
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    expect($option->fresh()->opening_notified_at)->not->toBeNull();

    // The window's start moves a month later; the announcement made was about the wrong date.
    $option->fresh()->update(['earliest_notice_date' => '2030-02-01']);
    expect($option->fresh()->opening_notified_at)->toBeNull();

    CarbonImmutable::setTestNow('2030-01-10');
    Notification::fake();
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'opening' && $n->option->is($option)
            && str_contains($n->toDatabase($this->manager)['body'], '2030-02-01'));
});

it('re-announces the corrected window through the CLOSING when the start moves after the closing went (SW-258)', function () {
    // SW-257 never sends an opening after a closing — rightly — so the closing is the only alert
    // that can carry a corrected start. Leave it stamped and the last thing the system said about
    // the window is wrong, with nothing to follow it: a colleague serving notice on the old start
    // is refused, and the alert in their inbox says otherwise.
    CarbonImmutable::setTestNow('2030-03-20');
    $option = optionOn(optionLease(), ['opening_notified_at' => now()->subMonths(3)]);
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    expect($option->fresh()->closing_notified_at)->not->toBeNull();

    $option->fresh()->update(['earliest_notice_date' => '2030-03-25']);
    expect($option->fresh()->closing_notified_at)->toBeNull();

    CarbonImmutable::setTestNow('2030-03-21');
    Notification::fake();
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'closing' && $n->option->is($option)
            && str_contains($n->toDatabase($this->manager)['body'], '2030-03-25'));
    Notification::assertNotSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'opening');
});

it('lets a caller that states a stamp in the same save rule on it — the idiom the lease\'s reminder uses', function () {
    // No door does this today; the guard keeps a future fixture or console act from being silently
    // overridden, exactly as `Lease::updating` (SW-048) reads a stamp stated beside the date.
    CarbonImmutable::setTestNow('2030-03-20');
    $option = optionOn(optionLease(), ['opening_notified_at' => now()->subMonths(3)]);

    $option->forceFill(['latest_notice_date' => '2030-06-01', 'closing_notified_at' => now()])->save();

    expect($option->fresh()->closing_notified_at)->not->toBeNull()
        ->and($option->fresh()->lapsed_notified_at)->toBeNull();
});

it('keeps every stamp on a save that moves no date — the control for SW-258', function () {
    // The scan's own stamp write, an edit to the notes, a rent-basis correction: none of them is
    // a re-dating, and a hook that cleared on every save would make the scan re-alert daily.
    CarbonImmutable::setTestNow('2030-03-20');
    $option = optionOn(optionLease(), ['opening_notified_at' => now()->subMonths(3)]);
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    $stamped = $option->fresh();
    expect($stamped->closing_notified_at)->not->toBeNull();

    // As the Edit modal does: both DatePickers resubmit their unchanged 'Y-m-d' beside the edit.
    $stamped->update([
        'earliest_notice_date' => '2030-01-01', 'latest_notice_date' => '2030-04-01',
        'notes' => 'Tenant called; deciding next week.', 'uplift_percent' => 12,
    ]);

    $again = $option->fresh();
    expect($again->opening_notified_at)->not->toBeNull()
        ->and($again->closing_notified_at)->not->toBeNull();

    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertSentTimes(LeaseOptionWindowNotification::class, 1);
});

it('forgets the lapse when a lapsed option is REOPENED — with its dates untouched (SW-258)', function () {
    // Reopening is the operator's act (the status is a field on the tab). Its window is still
    // closed, so the honest answer is to lapse it AGAIN and say so — not to leave it open in
    // silence because the lapse was "already announced". Dates untouched, deliberately: the
    // first cut of this case also moved the deadline, and the date rule cleared the stamp before
    // the status rule was ever asked — mutation showed the status rule could be deleted.
    CarbonImmutable::setTestNow('2030-05-01');
    $option = optionOn(optionLease(), [
        'opening_notified_at' => now()->subMonths(5),
        'closing_notified_at' => now()->subMonths(2),
    ]);
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    expect($option->fresh()->status)->toBe('lapsed')
        ->and($option->fresh()->lapsed_notified_at)->not->toBeNull();

    $option->fresh()->update(['status' => 'open']);
    expect($option->fresh()->lapsed_notified_at)->toBeNull()
        // …and its resolution date, or the audit trail shows an OPEN option carrying one.
        ->and($option->fresh()->resolved_at)->toBeNull();

    Notification::fake();
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    expect($option->fresh()->status)->toBe('lapsed');
    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'lapsed' && $n->option->is($option));
});

it('never reopens a lapsed option itself when its recorded deadline is corrected (SW-258)', function () {
    // An operator fixing the recorded date on an option that genuinely lapsed must not find it
    // live — and encumbering a unit — again. The stamp goes (the lapse was about the old date);
    // the status stays theirs.
    CarbonImmutable::setTestNow('2030-05-01');
    $option = optionOn(optionLease(), [
        'opening_notified_at' => now()->subMonths(5),
        'closing_notified_at' => now()->subMonths(2),
    ]);
    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    $option->fresh()->update(['latest_notice_date' => '2030-04-15']);

    expect($option->fresh()->status)->toBe('lapsed')
        ->and($option->fresh()->lapsed_notified_at)->toBeNull();

    // The status assertion is the tooth; a lapsed option is never a candidate (`status = open`),
    // so this last line only pins that nothing re-enters the scan through the back door.
    Notification::fake();
    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertNothingSent();
});

it('records a missed window as lapsed instead of leaving it open forever', function () {
    CarbonImmutable::setTestNow('2030-05-01'); // a month past the deadline
    $option = optionOn(optionLease(), [
        'opening_notified_at' => now()->subMonths(5),
        'closing_notified_at' => now()->subMonths(2),
    ]);

    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    $option->refresh();
    expect($option->status)->toBe('lapsed')
        ->and($option->resolved_at)->not->toBeNull();

    Notification::assertSentTo($this->manager, LeaseOptionWindowNotification::class,
        fn ($n) => $n->event === 'lapsed');
});

it('says nothing while the window is still far away', function () {
    CarbonImmutable::setTestNow('2029-06-01'); // seven months before it opens
    optionOn(optionLease());

    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    Notification::assertNothingSent();
});

/* ---- the scheduled-scan invariants ----------------------------------------- */

it('is idempotent — a second run does not re-notify', function () {
    CarbonImmutable::setTestNow('2029-12-12');
    optionOn(optionLease());

    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertSentTimes(LeaseOptionWindowNotification::class, 1);

    $this->artisan('leases:scan-option-windows')->assertSuccessful();
    Notification::assertSentTimes(LeaseOptionWindowNotification::class, 1);
});

it('writes and sends nothing on a dry run', function () {
    CarbonImmutable::setTestNow('2029-12-12');
    $option = optionOn(optionLease());

    $this->artisan('leases:scan-option-windows', ['--dry-run' => true])->assertSuccessful();

    Notification::assertNothingSent();
    expect($option->fresh()->opening_notified_at)->toBeNull();
});

it('ignores options on a lease that is no longer live', function () {
    CarbonImmutable::setTestNow('2029-12-12');
    optionOn(optionLease(['status' => 'terminated']));

    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    Notification::assertNothingSent();
});

it('ignores an option that has already been resolved', function () {
    CarbonImmutable::setTestNow('2030-05-01');
    $option = optionOn(optionLease(), ['status' => 'exercised']);

    $this->artisan('leases:scan-option-windows')->assertSuccessful();

    Notification::assertNothingSent();
    expect($option->fresh()->status)->toBe('exercised'); // not overwritten to lapsed
});

/* ---- the model rules ------------------------------------------------------- */

it('projects the rent an option would produce, and refuses to invent one it cannot know', function () {
    $lease = optionLease();

    expect(optionOn($lease, ['rent_basis' => 'uplift_percent', 'uplift_percent' => 10])
        ->projectedRent(100000))->toBe(110000.0)
        ->and(optionOn($lease, ['rent_basis' => 'fixed', 'fixed_rent' => 125000])
            ->projectedRent(100000))->toBe(125000.0)
        // A market review needs a valuation and CPI needs an index feed. Neither is a number this
        // system may make up — the same rule the escalation sweep follows.
        ->and(optionOn($lease, ['rent_basis' => 'market'])->projectedRent(100000))->toBeNull()
        ->and(optionOn($lease, ['rent_basis' => 'cpi'])->projectedRent(100000))->toBeNull();
});

it('encumbers a unit only while the option is still open', function () {
    $lease = optionLease();
    $unit = makeUnit($this->asset);

    $open = optionOn($lease, ['type' => 'expansion', 'unit_id' => $unit->id]);
    $exercised = optionOn($lease, ['type' => 'expansion', 'unit_id' => $unit->id, 'status' => 'exercised']);
    $renewal = optionOn($lease, ['type' => 'renewal', 'unit_id' => $unit->id]);

    expect($open->encumbersUnit())->toBeTrue()
        // A resolved option ties up nothing — treating it as if it did would block space the mall
        // is free to let.
        ->and($exercised->encumbersUnit())->toBeFalse()
        // A renewal is about this lease's own space, not a claim on another unit.
        ->and($renewal->encumbersUnit())->toBeFalse();
});

it('knows whether notice may be served today', function () {
    $lease = optionLease();
    $option = optionOn($lease);

    expect($option->windowIsOpen(CarbonImmutable::parse('2029-12-01')))->toBeFalse() // too early
        ->and($option->windowIsOpen(CarbonImmutable::parse('2030-02-01')))->toBeTrue()
        ->and($option->windowIsOpen(CarbonImmutable::parse('2030-05-01')))->toBeFalse() // too late
        ->and($option->windowHasClosed(CarbonImmutable::parse('2030-05-01')))->toBeTrue()
        ->and($option->daysUntilClose(CarbonImmutable::parse('2030-03-22')))->toBe(10);
});

/* ---- the window must BE a window (validation sweep, leasing, 2026-08-11) ---------------------- */

/**
 * `LeaseOption` had no `booted()` at all: the ordering lived as a single
 * `->afterOrEqual('earliest_notice_date')` on `LeaseOptionsRelationManager`'s DatePicker, so any
 * other writer (import, API, console, the read-only encumbrances manager's parent flows) walked
 * past it.
 *
 * An inverted window is not cosmetic, and the tests above are exactly why: every alert in this file
 * keys off `windowIsOpen()` / `windowHasClosed()`, and a window that starts after it ends is
 * simultaneously never-open and already-closed. The scan would announce the option as LAPSED
 * having never once announced it as open — the right the tenant negotiated silently unusable, with
 * the alerting that exists to protect it doing the opposite.
 */
it('refuses an option whose notice window closes before it opens', function () {
    expect(fn () => optionOn(optionLease(), [
        'earliest_notice_date' => '2030-04-01',
        'latest_notice_date' => '2030-01-01',
    ]))->toThrow(DomainException::class);
});

it('refuses EDITING an option into an inverted window', function () {
    $option = optionOn(optionLease());

    expect(fn () => $option->update(['latest_notice_date' => '2029-01-01']))
        ->toThrow(DomainException::class);

    // Control: a valid move still saves, so the refusal is the inversion and not a freeze.
    expect(fn () => $option->update(['latest_notice_date' => '2030-06-01']))
        ->not->toThrow(DomainException::class);
});

it('allows a single-day window (earliest == latest)', function () {
    // "Notice must be served on 1 April" is a real contract term, not an error.
    expect(optionOn(optionLease(), [
        'earliest_notice_date' => '2030-04-01',
        'latest_notice_date' => '2030-04-01',
    ])->exists)->toBeTrue();
});

it('allows a half-open window — either bound may be absent', function () {
    // Both columns are nullable and the model treats a null bound as unbounded on that side;
    // an option with no stated deadline is ordinary.
    expect(optionOn(optionLease(), ['latest_notice_date' => null])->exists)->toBeTrue();
    expect(optionOn(optionLease(), ['earliest_notice_date' => null, 'type' => 'termination'])->exists)->toBeTrue();
});
