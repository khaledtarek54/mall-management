<?php

use App\Console\Commands\EstimateMissingSalesCommand;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Notifications\SalesDeclarationReminderNotification;
use App\Settings\HousekeepingSettings;
use Carbon\CarbonImmutable;

/**
 * Regression — SW-253. An estimated sales declaration follows a RECORDED reminder, never a date.
 *
 * `sales:estimate-missing` ran "a week after the chase" as a schedule day (the 10th, then the
 * 17th) and never asked whether the chase had happened. SW-252 showed it can silently not: a
 * mail-first channel over a dead transport swallowed August's whole run on the staging soak, and
 * the 17th would then have billed an estimate to tenants nobody had asked. The benchmark documents
 * Voyager billing an estimate on a missing declaration and no notice as a prerequisite; requiring
 * one is Atriom's stricter reading, stated, and this makes the notice a STAMP. `Lease::salesDeclarationRemindedAt()` is the one definition — the record the chase
 * writes and reads for its own idempotency — and the estimate requires it to be at least
 * `--after-days` (7) whole days old.
 *
 * Every refusal here is paired with the control that the estimate still lands when the reminder
 * IS on record, because a "fix" that stopped estimating altogether would satisfy the refusals.
 */
function sw253Lease(string $historyFrom = '2026-03-01'): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000, 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 500000, 'percentage_rent_rate' => 5,
    ]);

    foreach ([800000, 900000, 1000000] as $i => $sales) {
        $month = CarbonImmutable::parse($historyFrom)->addMonths($i);
        TenantSalesDeclaration::create([
            'lease_id' => $lease->id,
            'period_start' => $month->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'declared_sales' => $sales,
            'declared_at' => $month->endOfMonth(),
            'status' => 'locked',
        ]);
    }

    return $lease->fresh();
}

/** The reminder as the chase records it — the tenant's own bell row — dated `$on`. */
function sw253RemindedOn(Lease $lease, string $periodKey, string $on): void
{
    CarbonImmutable::setTestNow($on);
    $lease->tenant->notify(new SalesDeclarationReminderNotification($lease, CarbonImmutable::parse($periodKey.'-01')->format('F Y'), $periodKey));
}

function sw253Estimates(Lease $lease, string $periodStart): int
{
    return TenantSalesDeclaration::where('lease_id', $lease->id)->whereDate('period_start', $periodStart)->where('is_estimate', true)->count();
}

it('does not estimate a tenant nobody chased — and says so where the box can read it', function () {
    $lease = sw253Lease();
    CarbonImmutable::setTestNow('2026-07-17');

    // The console half...
    $ops = captureOpsLog(fn () => $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])
        ->expectsOutputToContain('no reminder on record')
        ->expectsOutputToContain($lease->reference.' · 2026-06')
        ->assertSuccessful());

    expect(sw253Estimates($lease, '2026-06-01'))->toBe(0);

    // ...and the half that survives cron's /dev/null: the finding, and the run's own outcome.
    $skipped = collect($ops)->firstWhere('message', 'sales.estimate_skipped_unchased');
    $run = collect($ops)->firstWhere('message', 'sales.estimate_run');

    expect($skipped)->not->toBeNull()
        ->and($skipped['level'])->toBe('warning')
        ->and($skipped['context']['leases'])->toBe([$lease->reference.' · 2026-06'])
        ->and($run)->not->toBeNull()
        ->and($run['context'])->toMatchArray(['periods' => ['2026-06'], 'raised' => 0, 'unchased' => 1]);
});

it('writes nothing to the ops log on a dry run', function () {
    $lease = sw253Lease();
    CarbonImmutable::setTestNow('2026-07-17');

    $ops = captureOpsLog(fn () => $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01', '--dry-run' => true])
        ->expectsOutputToContain('no reminder on record')
        ->assertSuccessful());

    expect(collect($ops)->pluck('message')->all())->toBe([]);
});

it('does not estimate a tenant reminded yesterday — the week is the tenant\'s to answer in', function () {
    $lease = sw253Lease();
    sw253RemindedOn($lease, '2026-06', '2026-07-16');
    CarbonImmutable::setTestNow('2026-07-17');

    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])
        ->expectsOutputToContain('too soon')
        ->assertSuccessful();

    expect(sw253Estimates($lease, '2026-06-01'))->toBe(0);
});

it('counts the week in calendar days, so Egypt\'s DST-start day is not a day short', function () {
    // Cairo has no 00:00 on the last Friday of April — clocks jump 00:00 → 01:00 — so a
    // `startOfDay()`-based day diff read the seventh day as 6.96 and deferred the estimate a month.
    // The suite pins UTC (phpunit.xml), which is why this had to be found by probe; this case runs
    // the command in the box's own zone for its own length.
    $previous = date_default_timezone_get();
    config(['app.timezone' => 'Africa/Cairo']);
    date_default_timezone_set('Africa/Cairo');

    try {
        // History Dec 2025 – Feb 2026, so the missing period is March and its chase falls on the
        // DST-start Friday. (Not the default fixture with its March row deleted: the row soft-
        // deletes, the unique (lease, period) index still holds it, and `raise()` then fails on a
        // duplicate key that is caught and logged — a green-looking zero, found while writing this.)
        $lease = sw253Lease('2025-12-01');

        sw253RemindedOn($lease, '2026-03', '2026-04-24 08:00:00');   // the DST-start Friday
        CarbonImmutable::setTestNow('2026-05-01 07:30:00');            // the seventh day

        $this->artisan('sales:estimate-missing', ['--period' => '2026-03-01'])->assertSuccessful();

        expect(sw253Estimates($lease, '2026-03-01'))->toBe(1);
    } finally {
        date_default_timezone_set($previous);
        config(['app.timezone' => $previous]);
    }
});

it('keeps its stamp inside the housekeeping retention — the bell row is what it reads', function () {
    // `atriom:prune-transient-data` deletes notification rows by age. The oldest reminder the
    // default run consults was chased on the 10th of M+1 and is still inside the lookback on the
    // 17th of M+3 — (LOOKBACK − 1) months and a week, ~69 days at 31-day months; a shorter
    // retention would make a chased lease read as never chased.
    $oldestConsulted = (EstimateMissingSalesCommand::LOOKBACK_MONTHS - 1) * 31 + 7;

    expect(app(HousekeepingSettings::class)->notification_retention_days)->toBeGreaterThanOrEqual($oldestConsulted);
});

it('estimates once the reminder is a week old — the control, on the ordinary schedule', function () {
    // The chase runs on the 10th at 08:00 and the estimate on the 17th at 07:30: a week by the
    // calendar and thirty minutes short of one by the clock. Whole days, or the shipped schedule
    // never fires.
    $lease = sw253Lease();
    sw253RemindedOn($lease, '2026-06', '2026-07-10 08:00:00');
    CarbonImmutable::setTestNow('2026-07-17 07:30:00');

    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])->assertSuccessful();

    $estimate = TenantSalesDeclaration::where('lease_id', $lease->id)->whereDate('period_start', '2026-06-01')->sole();

    expect($estimate->is_estimate)->toBeTrue()
        ->and((float) $estimate->declared_sales)->toBe(900000.0)
        ->and($estimate->status)->toBe('submitted');
});

it('reads the same record the chase writes — the scan and the estimate cannot disagree', function () {
    // Through the REAL chase rather than a hand-written bell row: whatever the scan records is
    // what the estimate must find, or the two commands hold two definitions of "chased".
    $lease = sw253Lease();
    CarbonImmutable::setTestNow('2026-07-10');
    $this->artisan('sales:scan-missing-declarations', ['--period' => '2026-06-01'])->assertSuccessful();

    expect($lease->salesDeclarationRemindedAt('2026-06')?->toDateString())->toBe('2026-07-10');

    // ...and the chase's own idempotency reads it back: a second run reminds nobody.
    $this->artisan('sales:scan-missing-declarations', ['--period' => '2026-06-01'])
        ->expectsOutputToContain('Reminded 0')
        ->assertSuccessful();
});

it('looks back three declarable months, so a chase that arrived late still ends in an estimate', function () {
    // August's chase was lost (SW-252) and re-run on 11 September; the 17th finds it six days old
    // and skips; without a lookback, nothing would ever estimate August again. On 17 October the
    // default run (no --period) reaches back and raises it.
    $lease = sw253Lease();
    foreach (['2026-06', '2026-07'] as $key) {
        $m = CarbonImmutable::parse($key.'-01');
        TenantSalesDeclaration::create(['lease_id' => $lease->id, 'period_start' => $m->toDateString(), 'period_end' => $m->endOfMonth()->toDateString(), 'declared_sales' => 950000, 'declared_at' => $m->endOfMonth(), 'status' => 'locked']);
    }
    sw253RemindedOn($lease, '2026-08', '2026-09-11');
    sw253RemindedOn($lease, '2026-09', '2026-10-10');

    CarbonImmutable::setTestNow('2026-09-17');
    $this->artisan('sales:estimate-missing')->expectsOutputToContain('too soon')->assertSuccessful();
    expect(sw253Estimates($lease, '2026-08-01'))->toBe(0);

    CarbonImmutable::setTestNow('2026-10-17');
    $this->artisan('sales:estimate-missing')
        ->expectsOutputToContain('Raised 2 estimated declaration(s) for Jul 2026 – Sep 2026')
        ->assertSuccessful();

    expect(sw253Estimates($lease, '2026-08-01'))->toBe(1)
        ->and(sw253Estimates($lease, '2026-09-01'))->toBe(1)
        ->and(EstimateMissingSalesCommand::LOOKBACK_MONTHS)->toBe(3);
});

afterEach(fn () => CarbonImmutable::setTestNow());
