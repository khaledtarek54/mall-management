<?php

/**
 * A `Y-m` period must mean that month — on every day of every month.
 *
 * `CarbonImmutable::createFromFormat('Y-m', '2026-02')` supplies no day, so Carbon
 * fills it from TODAY. On the 29th–31st that overflows a shorter month: parsed on
 * 29 July, "2026-02" became **1 March**, and `->startOfMonth()` then happily
 * returned March. The period silently shifted by a month.
 *
 * It reached three call sites, two of which spend money:
 *   - Reports::parsePeriod()            → monthly close showed another month's figures
 *   - RunMonthlyBilling                 → billing run for the WRONG MONTH
 *   - RunMonthlyBillingCommand          → same, from the CLI
 *
 * The bug is invisible for 28 days a month, which is exactly why it needs a test
 * that does not depend on the day it happens to run.
 */

use App\Filament\Admin\Pages\Reports;
use App\Jobs\RunMonthlyBilling;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('resolves a period to its own month whatever today is', function (string $today) {
    Carbon::setTestNow(CarbonImmutable::parse($today));

    // February is the trap: it is shorter than every other month, so any "today"
    // after the 28th overflows it.
    expect(Reports::parsePeriod('2026-02')->format('Y-m-d'))->toBe('2026-02-01');

    // …and the 30-day months, for a 31st.
    foreach (['2026-04', '2026-06', '2026-09', '2026-11'] as $period) {
        expect(Reports::parsePeriod($period)->format('Y-m'))->toBe($period);
    }
})->with([
    'the 28th' => '2026-07-28',
    'the 29th — overflows February' => '2026-07-29',
    'the 30th — overflows February' => '2026-07-30',
    'the 31st — overflows Feb, Apr, Jun, Sep, Nov' => '2026-07-31',
    'the 1st' => '2026-07-01',
]);

it('parses a period to midnight, not to the current time of day', function () {
    // Left unanchored, the parsed period also carried the wall-clock time, so a
    // period boundary drifted through the day: an invoice issued at 00:30 on the
    // 1st fell outside a period parsed at 09:00.
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-29 14:37:11'));

    expect(Reports::parsePeriod('2026-05')->toDateTimeString())->toBe('2026-05-01 00:00:00');
});

it('still falls back to the current month for junk input', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-29'));

    expect(Reports::parsePeriod('not-a-date')->format('Y-m'))->toBe('2026-07')
        ->and(Reports::parsePeriod(null)->format('Y-m'))->toBe('2026-07');
});

it('bills the month it was asked for, on a day that overflows February', function () {
    // The money half, driven through the real job. RunMonthlyBilling parses the
    // same way, so on the 29th a run for February would have generated MARCH's
    // invoices — against March's leases, dated March, numbered for March.
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-29'));

    $seen = null;

    // The service is where the period lands; capture what the job hands it.
    $this->mock(MonthlyBillingService::class, function ($mock) use (&$seen) {
        $mock->shouldReceive('runForPeriod')
            ->once()
            ->andReturnUsing(function (CarbonImmutable $period) use (&$seen) {
                $seen = $period;

                return ['invoices' => 0];
            });
    });

    (new RunMonthlyBilling('2026-02'))->handle(app(MonthlyBillingService::class));

    expect($seen)->not->toBeNull()
        ->and($seen->format('Y-m-d'))->toBe('2026-02-01');
});
