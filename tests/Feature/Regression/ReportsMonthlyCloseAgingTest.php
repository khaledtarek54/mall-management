<?php

/*
|--------------------------------------------------------------------------
| Reports module (17) — the monthly-close page told three different stories
|--------------------------------------------------------------------------
| Three bugs the page-level tests couldn't see, because each one only shows up
| when you compare two surfaces against each other:
|
|   1. DUPLICATED CARDS. The page registered MonthlyCloseStats via
|      getHeaderWidgets() AND printed the (deprecated) widgets component in its
|      own view. Filament's page component renders header widgets itself, so
|      every KPI and every ageing bucket appeared twice.
|
|   2. THE PICKER DIDN'T REACH THE CARDS. The period was published through
|      `getHeaderWidgetsData()` — a hook Filament 4 never calls. The revenue
|      table read $this->period directly and moved; the cards stayed pinned to
|      the current month. Changing the picker made the two halves of one page
|      describe different months.
|
|   3. THE BUCKETS DIDN'T MATCH THE INVOICES BEHIND THEM. monthlyClose() aged
|      at month-END while the drill-down aged at NOW. For the month being
|      closed, month-end is a FUTURE date: on the demo books the "1–30 days"
|      card read 81 invoices / EGP 1.01m, and clicking it listed 2 / EGP 71k.
|      The fix ages at min(month-end, today) and carries that day to the
|      drill-down, so a bucket total and the worklist behind it always agree.
*/

use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\Reports;
use App\Filament\Admin\Widgets\MonthlyCloseStats;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
});

/* ---- 1 + 2: the page's own wiring ---------------------------------------- */

it('registers the monthly-close stats exactly once', function () {
    // getHeaderWidgets() is what Filament's page component renders on its own.
    // The view renders `statsWidgets`; if the widget were ALSO registered as a
    // header widget, every card would be printed twice.
    $page = new Reports;

    expect(invade($page)->getHeaderWidgets())->toBe([])
        ->and(method_exists($page, 'statsWidgets'))->toBeTrue();
});

it('publishes the selected period to the stats widget', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        $page = Livewire::test(Reports::class)->set('period', '2025-11');

        // getWidgetData() is the hook Filament actually reads. The old spelling
        // (getHeaderWidgetsData) was dead code, so this returned no period at all.
        expect($page->instance()->getWidgetData())->toBe(['period' => '2025-11']);
    });
});

it('shows the same month on the cards as in the revenue table', function () {
    // Two invoices in two different months. Whichever month is picked, the KPI
    // card and the revenue table must both describe THAT month.
    makeInvoice($this->lease, [
        'issue_date' => '2026-02-10', 'status' => 'issued',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ])->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);
    makeInvoice($this->lease, [
        'issue_date' => '2026-03-10', 'status' => 'issued',
        'subtotal' => 7000, 'vat_amount' => 0, 'total' => 7000, 'balance' => 7000,
    ])->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => 7000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 7000,
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        foreach (['2026-02' => 10000.0, '2026-03' => 7000.0] as $period => $expected) {
            // The card's number, produced the way the widget produces it...
            $widget = Livewire::test(MonthlyCloseStats::class, ['period' => $period]);
            $cards = (float) app(ReportService::class)
                ->monthlyClose(Reports::parsePeriod($period))['invoices']['total'];

            // ...and the table's number on the same page.
            $table = collect(Livewire::test(Reports::class)->set('period', $period)
                ->instance()->getTableRecords());

            $widget->assertOk();
            expect($cards)->toBe($expected)
                ->and(round((float) $table->sum('amount'), 2))->toBe($expected);
        }
    });
});

/* ---- 3: buckets vs the worklist behind them ------------------------------ */

it('ages the month being closed at today, not at a month-end that has not happened', function () {
    // An invoice due 5 days ago: late by 5 days TODAY, but 30+ days late when
    // aged at a month-end still in the future.
    $this->travelTo(CarbonImmutable::parse('2026-03-05')->setTime(14, 0));

    makeInvoice($this->lease, [
        'issue_date' => '2026-02-01', 'due_date' => '2026-02-28',
        'status' => 'issued', 'total' => 4000, 'balance' => 4000,
    ]);

    $report = app(ReportService::class)->monthlyClose(CarbonImmutable::parse('2026-03-01'));

    expect($report['ar_aging_as_of'])->toBe('2026-03-05')
        ->and($report['ar_aging']['d_1_30']['total'])->toBe(4000.0)
        ->and($report['ar_aging']['d_31_60']['total'])->toBe(0.0);
});

it('ages a month that is already closed at its own month-end', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15')->setTime(9, 30));

    makeInvoice($this->lease, [
        'issue_date' => '2026-01-01', 'due_date' => '2026-01-31',
        'status' => 'issued', 'total' => 4000, 'balance' => 4000,
    ]);

    // Reporting on February: the receivable was 28 days late at 2026-02-28,
    // even though it is >90 days late by the time the report is opened.
    $report = app(ReportService::class)->monthlyClose(CarbonImmutable::parse('2026-02-01'));

    expect($report['ar_aging_as_of'])->toBe('2026-02-28')
        ->and($report['ar_aging']['d_1_30']['total'])->toBe(4000.0)
        ->and($report['ar_aging']['d_90_plus']['total'])->toBe(0.0);
});

it('reconciles every bucket total with the invoices the drill-down lists', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-05')->setTime(14, 0));

    // One invoice per bucket, relative to 2026-03-05.
    foreach ([
        ['2026-03-20', 1000],  // not due yet     → current
        ['2026-02-28', 2000],  // 5 days late     → 1–30
        ['2026-01-20', 3000],  // 44 days late    → 31–60
        ['2025-12-20', 4000],  // 75 days late    → 61–90
        ['2025-09-01', 5000],  // 185 days late   → 90+
    ] as [$due, $amount]) {
        makeInvoice($this->lease, [
            'issue_date' => CarbonImmutable::parse($due)->subMonth()->toDateString(),
            'due_date' => $due, 'status' => 'issued',
            'total' => $amount, 'balance' => $amount,
        ]);
    }

    $svc = app(ReportService::class);
    $report = $svc->monthlyClose(CarbonImmutable::parse('2026-03-01'));
    $asOf = ArAging::parseAsOf($report['ar_aging_as_of']);

    foreach ($report['ar_aging'] as $bucket => $row) {
        $drilldown = $svc->arAgingDrilldown($bucket, $asOf);

        expect($drilldown->count())->toBe($row['count'], "bucket {$bucket} count")
            ->and(round((float) $drilldown->sum('balance'), 2))
            ->toBe($row['total'], "bucket {$bucket} total");
    }

    // …and every bucket is actually populated, so the assertion above isn't
    // passing on five empty buckets.
    expect(array_sum(array_column($report['ar_aging'], 'count')))->toBe(5);
});

it('opens the drill-down on the ageing date the clicked card was computed at', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15')->setTime(9, 30));

    makeInvoice($this->lease, [
        'issue_date' => '2026-01-01', 'due_date' => '2026-01-31',
        'status' => 'issued', 'total' => 4000, 'balance' => 4000,
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        // The card links must carry the as-of date, not just the bucket —
        // otherwise the page re-ages at "now" and lists a different set.
        $widget = Livewire::test(MonthlyCloseStats::class, ['period' => '2026-02']);
        $html = $widget->assertOk()->html();

        expect($html)->toContain('asOf=2026-02-28');

        // Following that link puts the invoice in 1–30, matching the card…
        $page = Livewire::test(ArAging::class, ['bucket' => 'd_31_60'])
            ->set('bucket', 'd_1_30')
            ->set('asOf', '2026-02-28');

        expect(collect($page->instance()->getTableRecords())->sum('balance'))->toEqual(4000.0);

        // …while ageing at today (the old behaviour) would have put it in 90+.
        $page->set('asOf', '2026-06-15');
        expect(collect($page->instance()->getTableRecords()))->toBeEmpty();
    });
});

it('falls back to today when the as-of date is missing or junk', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15')->setTime(9, 30));

    expect(ArAging::parseAsOf(null)->toDateString())->toBe('2026-06-15')
        ->and(ArAging::parseAsOf('not-a-date')->toDateString())->toBe('2026-06-15')
        ->and(ArAging::parseAsOf('2026-02-28')->toDateString())->toBe('2026-02-28');
});

/* ---- the dashboard chart is the third surface showing these buckets ------- */

it('gives the dashboard chart the same buckets as the report', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-05')->setTime(14, 0));

    // Due EXACTLY 30 days before today. The chart used to compare a midnight
    // due_date against a `now()` carrying a time, pushing every boundary
    // invoice one bucket too far — so it disagreed with the report.
    makeInvoice($this->lease, [
        'issue_date' => '2026-01-15', 'due_date' => '2026-02-03',
        'status' => 'issued', 'total' => 6000, 'balance' => 6000,
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        $chart = invade(new App\Filament\Admin\Widgets\ArAging)->getData();
        $buckets = app(ReportService::class)->arAgingBuckets();

        expect($chart['datasets'][0]['data'])->toBe(array_values(array_column($buckets, 'total')))
            ->and($chart['datasets'][0]['counts'])->toBe(array_values(array_column($buckets, 'count')))
            // and the boundary invoice is in 1–30, not 31–60
            ->and($buckets['d_1_30']['total'])->toBe(6000.0)
            ->and($buckets['d_31_60']['total'])->toBe(0.0);
    });
});
