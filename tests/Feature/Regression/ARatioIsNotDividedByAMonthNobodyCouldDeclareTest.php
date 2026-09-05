<?php

/**
 * SW-183 — occupancy cost and MAT measured twelve months of cost against eleven months of sales.
 *
 * Cost is BILLED on the first of a month; sales are DECLARED after the last. So a window that ends
 * inside the running month always carries a whole month of cost and no sales at all — the ratio is
 * overstated by exactly 12/11 (+9.09%) and the trailing-twelve-month growth figure is understated
 * by 1/12 (−8.33%) against a prior year that is always complete.
 *
 * Measured on the QA books at 2026-09-05: 250,945.00 of September occupancy cost against ZERO
 * September declarations, portfolio ratio 38.05% where twelve complete months read 36.88%.
 *
 * **The rule is the CALENDAR, not the data**, and the third case below is the whole argument for it
 * in numbers. "Skip any month with no declaration" would drop the missing month from BOTH sides, so
 * the tenant who stops filing would read the same healthy 23.0% as the one who files every month —
 * inverting the one signal this report exists to give. Under the calendar rule they read 25.09%,
 * which is worse, which is the truth.
 *
 * Every clamp assertion is paired with a control that must still produce a figure: a window that
 * had been emptied would satisfy the narrowing on its own.
 */

use App\Filament\Admin\Pages\OccupancyCost;
use App\Models\Asset;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

/** A percentage-rent lease on the given property. */
function sw183Lease(Asset $asset, string $code): Lease
{
    return makeLease(makeUnit($asset, ['code' => $code]), null, [
        'status' => 'active',
        'commencement_date' => '2024-01-01',
        'expiry_date' => '2032-12-31',
        'has_percentage_rent' => true,
    ])->fresh();
}

/** One month of BILLED occupancy cost, raised on the FIRST of the month as the billing run does. */
function sw183Bill(Lease $lease, string $month, float $amount): void
{
    $invoice = makeInvoice($lease, [
        'issue_date' => $month,
        'due_date' => $month,
        'period_start' => $month,
        'period_end' => CarbonImmutable::parse($month)->endOfMonth()->toDateString(),
        'status' => 'issued',
    ]);

    $invoice->items()->delete();
    $invoice->items()->create([
        'description' => 'Rent', 'type' => 'base_rent',
        'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
    ]);
}

/** One month of DECLARED sales, filed after the month closes — which is the whole point. */
function sw183Declare(Lease $lease, string $month, float $amount): void
{
    TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => $month,
        'period_end' => CarbonImmutable::parse($month)->endOfMonth()->toDateString(),
        'declared_sales' => $amount,
        'status' => 'submitted',
        'declared_at' => CarbonImmutable::parse($month)->addMonth()->toDateString(),
    ]);
}

beforeEach(function () {
    // 5 September 2026: September's rent is already billed and nobody can have declared September's
    // sales, because September has not finished.
    CarbonImmutable::setTestNow('2026-09-05');

    $this->asset = makeAsset(['code' => 'OC']);

    // 23,000 of occupancy cost against 100,000 of sales — a true ratio of 23.0%, inside the amber
    // band and BELOW the 25% danger line.
    $this->filer = sw183Lease($this->asset, 'OC-01');

    // The same tenancy, except that March 2026 was never declared.
    $this->gap = sw183Lease($this->asset, 'OC-02');

    // Sales for TWO full years, so MAT and the prior year are both covered. Nothing for September
    // 2026 — that month has not ended.
    for ($m = CarbonImmutable::parse('2024-09-01'); $m->lte(CarbonImmutable::parse('2026-08-01')); $m = $m->addMonth()) {
        sw183Declare($this->filer, $m->toDateString(), 100000);

        if (! $m->isSameMonth(CarbonImmutable::parse('2026-03-01'))) {
            sw183Declare($this->gap, $m->toDateString(), 100000);
        }
    }

    // Cost for thirteen months, September 2025 through September 2026 — INCLUDING the running month,
    // because that is exactly what the billing run does on the 1st.
    for ($m = CarbonImmutable::parse('2025-09-01'); $m->lte(CarbonImmutable::parse('2026-09-01')); $m = $m->addMonth()) {
        sw183Bill($this->filer, $m->toDateString(), 23000);
        sw183Bill($this->gap, $m->toDateString(), 23000);
    }
});

it('divides twelve months of cost by the twelve months that could actually be declared', function () {
    $row = app(ReportService::class)->occupancyCost(null, null, $this->asset->id)
        ->firstWhere('lease_id', $this->filer->id);

    // Twelve WHOLE months, ending with the last one that closed. The end moved off 30 September and
    // the start off 1 October — the second half of which is a separate bug the same edit fixes:
    // `subMonths()` overflows off a 31st, so the "rolling 12 months" was 11 in five months of the
    // year.
    expect($row['from']->toDateString())->toBe('2025-09-01')
        ->and($row['to']->toDateString())->toBe('2026-08-31')
        // The control: it still produces a figure, over twelve months of BOTH sides.
        ->and($row['months_declared'])->toBe(12)
        ->and($row['occupancy_cost'])->toBe(276000.0)
        ->and($row['declared_sales'])->toBe(1200000.0)
        ->and($row['occupancy_cost_pct'])->toBe(23.0);
});

it('keeps a genuine 23% tenant out of the red band the missing month put them in', function () {
    // The colour band is the entire reading of this report. Before the fix the window ran
    // Oct-2025 … Sep-2026: twelve months of cost over ELEVEN of sales, i.e. exactly ×12/11 →
    // 25.09%, over OccupancyCost::RED_PCT.
    $pct = (float) app(ReportService::class)->occupancyCost(null, null, $this->asset->id)
        ->firstWhere('lease_id', $this->filer->id)['occupancy_cost_pct'];

    expect($pct)->toBeLessThan(OccupancyCost::RED_PCT)
        // …and the control, so a fix that had merely zeroed the ratio would not pass: it is amber.
        ->and($pct)->toBeGreaterThanOrEqual(OccupancyCost::AMBER_PCT);
});

it('still counts a month the tenant failed to declare, so silence cannot make them look cheaper', function () {
    // THE design answer, in numbers. Excluding "any month with no declaration" would have dropped
    // March from both sides and handed this tenant the same healthy 23.0% as the one who files every
    // month. A month that has CLOSED stays in the window, and a tenant who did not file shows a
    // ratio that rises — which is what `months_declared` is on the page to explain.
    $row = app(ReportService::class)->occupancyCost(null, null, $this->asset->id)
        ->firstWhere('lease_id', $this->gap->id);

    expect($row['occupancy_cost'])->toBe(276000.0)
        ->and($row['months_declared'])->toBe(11)
        ->and($row['declared_sales'])->toBe(1100000.0)
        ->and($row['occupancy_cost_pct'])->toBe(25.09)
        ->and($row['occupancy_cost_pct'])->toBeGreaterThan(OccupancyCost::RED_PCT);
});

it('answers a window that lies inside the running month, rather than emptying it', function () {
    // The clamp must never move the end BEHIND a start the operator stated. Asked for September
    // alone it says what was billed, that nothing was declared, and an UNKNOWN ratio — which is what
    // a null means on this screen.
    $row = app(ReportService::class)->occupancyCost(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        $this->asset->id,
    )->firstWhere('lease_id', $this->filer->id);

    expect($row['occupancy_cost'])->toBe(23000.0)
        ->and($row['declared_sales'])->toBe(0.0)
        ->and($row['occupancy_cost_pct'])->toBeNull();
});

it('measures the trailing twelve months against a prior year of the same length', function () {
    // Flat trading, 100,000 every month for two years. With the running month inside MAT the
    // headline read −8.3% growth — eleven months of sales against a complete twelve — every month
    // of the year, on a portfolio where nothing had changed.
    $report = app(ReportService::class)->salesAnalytics(null, $this->asset->id);

    expect($report['mat_to']->toDateString())->toBe('2026-08-31')
        // …while the point the READER asked about is untouched. Only the comparison window moved.
        ->and($report['as_of']->toDateString())->toBe('2026-09-30');

    $row = $report['rows']->firstWhere('lease_id', $this->filer->id);

    expect($row['mat'])->toBe(1200000.0)
        ->and($row['prior_mat'])->toBe(1200000.0)
        ->and($row['mat_growth_pct'])->toBe(0.0)
        ->and($row['months_declared'])->toBe(12);
});

it('leaves month-to-date and year-to-date on the month the reader asked about', function () {
    // MTD and YTD are "to date" figures and the running month is what they are FOR. Clamping them
    // too would have made a column labelled This month show last month.
    $row = app(ReportService::class)->salesAnalytics(null, $this->asset->id)['rows']
        ->firstWhere('lease_id', $this->filer->id);

    expect($row['mtd'])->toBe(0.0)              // September is not declared yet
        ->and($row['ytd'])->toBe(800000.0);     // January–August 2026
});
