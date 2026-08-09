<?php

use App\Models\Lease;
use App\Models\LeaseOption;
use App\Notifications\LeaseOptionWindowNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
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
