<?php

use App\Models\Asset;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;

/**
 * Trading performance: MTD, YTD, MAT and like-for-like (phase 7, story RR-05).
 *
 * **MAT — the trailing twelve months — is the number retail runs on.** A calendar-year figure says
 * nothing useful in March and swings around Ramadan and the school year; twelve months strips the
 * seasonality out so two dates are comparable.
 *
 * **Like-for-like is the one that is easy to get wrong**, and it is what most of this file pins.
 * Comparing this year's MAT to last year's across the whole mall counts units that did not exist a
 * year ago — so a centre that let ten new shops shows "growth" that is just more shops. LFL counts
 * only leases that declared in BOTH windows: the difference between measuring trading and
 * measuring letting.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function salesLease(int $assetId, string $code): Lease
{
    return makeLease(makeUnit(Asset::find($assetId), ['code' => $code]), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2032-12-31',
        'has_percentage_rent' => true,
    ])->fresh();
}

function declareSales(Lease $lease, string $month, float $amount, bool $estimate = false): void
{
    TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => $month,
        'period_end' => CarbonImmutable::parse($month)->endOfMonth()->toDateString(),
        'declared_sales' => $amount,
        'is_estimate' => $estimate,
        'status' => 'submitted',
        'declared_at' => CarbonImmutable::parse($month)->addMonth()->toDateString(),
    ]);
}

it('rolls MTD, YTD and the trailing twelve months from the same declarations', function () {
    CarbonImmutable::setTestNow('2029-06-20');
    $asset = makeAsset();
    $lease = salesLease($asset->id, 'A-01');

    // 24 months at 100,000, so every window is unambiguous.
    for ($m = CarbonImmutable::parse('2027-07-01'); $m->lte(CarbonImmutable::parse('2029-06-01')); $m = $m->addMonth()) {
        declareSales($lease, $m->toDateString(), 100000);
    }

    $row = app(ReportService::class)->salesAnalytics(CarbonImmutable::parse('2029-06-30'), $asset->id)['rows']->sole();

    expect($row['mtd'])->toBe(100000.0)          // June 2029 alone
        ->and($row['ytd'])->toBe(600000.0)       // Jan–Jun 2029
        ->and($row['mat'])->toBe(1200000.0)      // Jul 2028 – Jun 2029
        ->and($row['prior_mat'])->toBe(1200000.0)
        ->and($row['mat_growth_pct'])->toBe(0.0);
});

it('reports growth against the same twelve months a year earlier', function () {
    CarbonImmutable::setTestNow('2029-06-20');
    $asset = makeAsset();
    $lease = salesLease($asset->id, 'A-01');

    for ($m = CarbonImmutable::parse('2027-07-01'); $m->lte(CarbonImmutable::parse('2028-06-01')); $m = $m->addMonth()) {
        declareSales($lease, $m->toDateString(), 100000);   // prior MAT 1,200,000
    }
    for ($m = CarbonImmutable::parse('2028-07-01'); $m->lte(CarbonImmutable::parse('2029-06-01')); $m = $m->addMonth()) {
        declareSales($lease, $m->toDateString(), 125000);   // MAT 1,500,000
    }

    $row = app(ReportService::class)->salesAnalytics(CarbonImmutable::parse('2029-06-30'), $asset->id)['rows']->sole();

    expect($row['mat_growth_pct'])->toBe(25.0)
        ->and($row['lfl_eligible'])->toBeTrue();
});

it('excludes a tenant with no prior year from like-for-like', function () {
    // THE trap. A mall that let a new anchor last month would otherwise report its whole turnover
    // as "growth", which measures letting rather than trading.
    CarbonImmutable::setTestNow('2029-06-20');
    $asset = makeAsset();

    $established = salesLease($asset->id, 'A-01');
    $newcomer = salesLease($asset->id, 'A-02');

    for ($m = CarbonImmutable::parse('2027-07-01'); $m->lte(CarbonImmutable::parse('2029-06-01')); $m = $m->addMonth()) {
        declareSales($established, $m->toDateString(), 100000);
    }
    // The newcomer only started trading three months ago — a huge shop, no history.
    for ($m = CarbonImmutable::parse('2029-04-01'); $m->lte(CarbonImmutable::parse('2029-06-01')); $m = $m->addMonth()) {
        declareSales($newcomer, $m->toDateString(), 900000);
    }

    $report = app(ReportService::class)->salesAnalytics(CarbonImmutable::parse('2029-06-30'), $asset->id);

    // Headline MAT includes them and looks like +225% growth…
    expect($report['mat'])->toBe(3900000.0)
        ->and($report['mat_growth_pct'])->toBe(225.0)
        // …while LIKE-FOR-LIKE, which is the honest number, is flat on one qualifying tenant.
        ->and($report['lfl_leases'])->toBe(1)
        ->and($report['lfl_mat'])->toBe(1200000.0)
        ->and($report['lfl_growth_pct'])->toBe(0.0);
});

it('excludes a tenant who has stopped declaring from like-for-like too', function () {
    // The mirror case: a departed tenant drags the headline down without saying anything about how
    // the remaining ones are trading.
    CarbonImmutable::setTestNow('2029-06-20');
    $asset = makeAsset();

    $trading = salesLease($asset->id, 'A-01');
    $departed = salesLease($asset->id, 'A-02');

    for ($m = CarbonImmutable::parse('2027-07-01'); $m->lte(CarbonImmutable::parse('2029-06-01')); $m = $m->addMonth()) {
        declareSales($trading, $m->toDateString(), 100000);
    }
    // Traded through the prior window only.
    for ($m = CarbonImmutable::parse('2027-07-01'); $m->lte(CarbonImmutable::parse('2028-06-01')); $m = $m->addMonth()) {
        declareSales($departed, $m->toDateString(), 500000);
    }

    $report = app(ReportService::class)->salesAnalytics(CarbonImmutable::parse('2029-06-30'), $asset->id);

    expect($report['mat_growth_pct'])->toBe(-83.3)     // the headline collapses
        ->and($report['lfl_leases'])->toBe(1)
        ->and($report['lfl_growth_pct'])->toBe(0.0);   // the tenants still there are flat
});

it('reports unknown growth, not zero, for a tenant with no prior sales', function () {
    // Zero would read as flat trading, which is a claim the data does not support.
    CarbonImmutable::setTestNow('2029-06-20');
    $asset = makeAsset();
    $lease = salesLease($asset->id, 'A-01');

    declareSales($lease, '2029-05-01', 400000);

    $row = app(ReportService::class)->salesAnalytics(CarbonImmutable::parse('2029-06-30'), $asset->id)['rows']->sole();

    expect($row['prior_mat'])->toBe(0.0)
        ->and($row['mat_growth_pct'])->toBeNull()
        ->and($row['lfl_eligible'])->toBeFalse();
});

it('flags a figure built on estimated declarations', function () {
    CarbonImmutable::setTestNow('2029-06-20');
    $asset = makeAsset();
    $lease = salesLease($asset->id, 'A-01');

    declareSales($lease, '2029-05-01', 400000, estimate: true);

    $report = app(ReportService::class)->salesAnalytics(CarbonImmutable::parse('2029-06-30'), $asset->id);

    expect($report['has_estimates'])->toBeTrue()
        ->and($report['rows']->sole()['has_estimates'])->toBeTrue();
});

it('stays inside the selected property', function () {
    CarbonImmutable::setTestNow('2029-06-20');
    $here = makeAsset();
    $elsewhere = makeAsset();

    declareSales(salesLease($here->id, 'A-01'), '2029-05-01', 100000);
    declareSales(salesLease($elsewhere->id, 'B-01'), '2029-05-01', 900000);

    expect(app(ReportService::class)->salesAnalytics(CarbonImmutable::parse('2029-06-30'), $here->id)['mat'])
        ->toBe(100000.0);
});
