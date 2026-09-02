<?php

use App\Filament\Admin\Pages\IncomeStatement;
use App\Models\BudgetLine;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ComparativeStatementService;
use App\Services\Reports\StatementSpread;
use App\Support\IssuingEntity;
use App\Support\Pdf\PdfDocument;
use App\Support\StatementSection;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The income statement can be read across SEVERAL columns at once (RP-07).
 *
 * A single-period P&L says what happened; it cannot answer the two questions a mall is actually run
 * on. **"Where are we against the year?"** is the month beside the year to date — the layout Yardi's
 * income statement opens in, and the one an owner asks for by name. **"Is anything running away?"**
 * is the twelve months side by side, where a cost creeping up shows as a shape instead of as twelve
 * reports somebody has to hold in their head.
 *
 * Both are ONE feature — a statement with N amount columns instead of one — which is why there is one
 * `StatementSpread` rather than a month-and-YTD report and a twelve-month report that would each have
 * their own idea of what a column of an income statement is.
 */
function spreadAccount(string $code, string $type, ?string $section): LedgerAccount
{
    return LedgerAccount::create([
        'code' => $code, 'name_en' => 'A '.$code, 'name_ar' => 'ح '.$code, 'type' => $type,
        'statement_section' => $section, 'is_postable' => true, 'is_active' => true,
    ]);
}

function spreadEntry(LedgerAccount $debit, LedgerAccount $credit, float $amount, string $on, ?int $assetId = null): void
{
    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(), 'entry_date' => $on, 'status' => 'draft', 'is_manual' => true,
        'asset_id' => $assetId,
    ]);

    $entry->lines()->create(['ledger_account_id' => $debit->id, 'debit' => $amount, 'credit' => 0]);
    $entry->lines()->create(['ledger_account_id' => $credit->id, 'debit' => 0, 'credit' => $amount]);
    $entry->update(['status' => 'posted']);
}

/**
 * Three months of a mall whose rent is flat and whose maintenance is running away — the exact story
 * a spread exists to make visible and a single-period statement cannot tell.
 */
function spreadThreeMonths(?int $assetId = null): void
{
    $cash = spreadAccount('1000', 'asset', null);
    $rent = spreadAccount('4100', 'revenue', StatementSection::OPERATING);
    $repairs = spreadAccount('5100', 'expense', StatementSection::OPERATING);
    $interest = spreadAccount('5210', 'expense', StatementSection::NON_OPERATING);

    foreach (['01' => 140_000, '02' => 180_000, '03' => 220_000] as $month => $cost) {
        spreadEntry($cash, $rent, 500_000, "2026-{$month}-10", $assetId);
        spreadEntry($repairs, $cash, $cost, "2026-{$month}-10", $assetId);
        spreadEntry($interest, $cash, 90_000, "2026-{$month}-10", $assetId);
    }
}

/** @return list<array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable}> */
function spreadMonths(array $months): array
{
    return collect($months)->map(function (string $month): array {
        $start = CarbonImmutable::parse($month.'-01');

        return ['key' => 'm'.str_replace('-', '', $month), 'label' => $start->format('M'),
            'from' => $start, 'to' => $start->endOfMonth()->endOfDay()];
    })->all();
}

it('reads the month beside the year to date', function () {
    spreadThreeMonths();

    $spread = app(StatementSpread::class)->incomeStatement([
        ['key' => 'period', 'label' => 'Mar', 'from' => CarbonImmutable::parse('2026-03-01'), 'to' => CarbonImmutable::parse('2026-03-31')->endOfDay()],
        ['key' => 'ytd', 'label' => 'YTD', 'from' => CarbonImmutable::parse('2026-01-01'), 'to' => CarbonImmutable::parse('2026-03-31')->endOfDay()],
    ]);

    expect($spread['totals']['operating_revenue'])->toBe(['period' => 500_000.0, 'ytd' => 1_500_000.0])
        ->and($spread['totals']['operating_expense'])->toBe(['period' => 220_000.0, 'ytd' => 540_000.0])
        ->and($spread['totals']['noi'])->toBe(['period' => 280_000.0, 'ytd' => 960_000.0])
        // The below-the-line column follows too, or the year-to-date bottom line would be the only
        // figure on the statement that had not been read across both spans.
        ->and($spread['totals']['net'])->toBe(['period' => 190_000.0, 'ytd' => 690_000.0]);
});

it('shows a cost running away month by month', function () {
    spreadThreeMonths();

    $spread = app(StatementSpread::class)->incomeStatement(spreadMonths(['2026-01', '2026-02', '2026-03']));

    expect(array_values($spread['totals']['operating_expense']))->toBe([140_000.0, 180_000.0, 220_000.0])
        // Rent flat, cost climbing — so NOI falls, which is the whole point of reading it this way.
        ->and(array_values($spread['totals']['noi']))->toBe([360_000.0, 320_000.0, 280_000.0]);
});

it('gives every row every column, so a spread is never ragged', function () {
    // An account with activity in one month and none in another must still print a row, or the empty
    // month would have no line to put its zero on. The row set is the UNION across every column.
    $cash = spreadAccount('1000', 'asset', null);
    $rent = spreadAccount('4100', 'revenue', StatementSection::OPERATING);
    $oneOff = spreadAccount('5100', 'expense', StatementSection::OPERATING);

    spreadEntry($cash, $rent, 500_000, '2026-01-10');
    spreadEntry($cash, $rent, 500_000, '2026-02-10');
    // February only.
    spreadEntry($oneOff, $cash, 75_000, '2026-02-10');

    $spread = app(StatementSpread::class)->incomeStatement(spreadMonths(['2026-01', '2026-02']));

    $row = collect($spread['rows'])->firstWhere('code', '5100');

    expect($row)->not->toBeNull()
        ->and($row['amounts'])->toBe(['m202601' => 0.0, 'm202602' => 75_000.0]);
});

it('derives the variance from the two columns beside it', function () {
    spreadThreeMonths();

    $spread = app(StatementSpread::class)->incomeStatement([
        ['key' => 'period', 'label' => 'Mar', 'from' => CarbonImmutable::parse('2026-03-01'), 'to' => CarbonImmutable::parse('2026-03-31')->endOfDay()],
    ], null, ComparativeStatementService::PRIOR_PERIOD);

    // Yardi's layout: actual, the comparison, and the variance between them, per group.
    expect(array_column($spread['spans'], 'kind'))->toBe(['actual', 'comparison', 'variance']);

    // March maintenance 220,000 against February's 180,000 — and the variance is the subtraction a
    // reader does in their head, never a third query that could disagree with it.
    expect($spread['totals']['operating_expense']['period'])->toBe(220_000.0)
        ->and($spread['totals']['operating_expense']['period'.StatementSpread::COMPARISON_SUFFIX])->toBe(180_000.0)
        ->and($spread['totals']['operating_expense']['period'.StatementSpread::VARIANCE_SUFFIX])->toBe(40_000.0)
        // NOI went the other way, which is the number that matters.
        ->and($spread['totals']['noi']['period'.StatementSpread::VARIANCE_SUFFIX])->toBe(-40_000.0);
});

it('keeps the statement the same shape across every reading', function () {
    // A spread must be this statement read across more columns, never a different report wearing its
    // name — so its sections are the ones `IncomeStatementLayout::shape()` names, in that order.
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    spreadThreeMonths($asset->id);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    $page = Livewire::test(IncomeStatement::class)
        ->set('year', 2026)
        ->set('period', '2026-03')
        ->set('spread', IncomeStatement::SPREAD_YTD)
        ->assertOk();

    $records = $page->instance()->spreadRecords($page->instance()->spreadReport());

    expect(collect($records)->pluck('section')->unique()->values()->all())->toBe([
        __('admin.reports.operating_revenue'),
        __('admin.reports.operating_expenses'),
        __('admin.reports.net_operating_income'),
        __('admin.reports.other_expenses'),
        __('admin.reports.net_profit'),
    ]);

    // And the columns really are two, carrying the two figures.
    $noi = collect($records)->firstWhere('section', __('admin.reports.net_operating_income'));

    expect($noi['a_period'])->toBe(280_000.0)
        ->and($noi['a_ytd'])->toBe(960_000.0);

    Filament::setTenant(null, isQuiet: true);
});

it('refuses month-and-year-to-date when no month is chosen', function () {
    // With the whole year selected the two columns would be identical figures printed twice, which
    // reads as an error — the rule this statement already applies to NOI and to a one-row subtotal.
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    spreadThreeMonths($asset->id);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    $page = Livewire::test(IncomeStatement::class)
        ->set('year', 2026)
        ->set('period', null)
        ->set('spread', IncomeStatement::SPREAD_YTD)
        ->assertOk();

    // It falls back to the single-period statement rather than showing a duplicated column…
    expect($page->instance()->spreadReport())->toBeNull();

    // …and the twelve-month reading needs no month, so it still answers.
    $page->set('spread', IncomeStatement::SPREAD_MONTHLY)->assertOk();

    expect($page->instance()->spreadReport())->not->toBeNull();
});

it('prints the columns the operator is looking at', function () {
    // The PDF is the copy that gets filed and argued over, so a button that quietly reverted to the
    // single-period statement would hand them a different document under the same name.
    //
    // `assertHasNoActionErrors()` CANNOT see that: both branches return a perfectly good PDF, so the
    // action succeeds either way and the test passes on the defect. Proved by mutation — forcing the
    // single-period branch left the obvious version of this test green. What the wiring is actually
    // asked is WHICH document it built.
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    spreadThreeMonths($asset->id);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    $pdf = $this->mock(LedgerReportPdfService::class);
    $pdf->shouldReceive('incomeStatementSpread')->once()->andReturn('%PDF-1.4');
    $pdf->shouldReceive('filename')->andReturn('income-statement-monthly.pdf');
    $pdf->shouldNotReceive('incomeStatement');

    Livewire::test(IncomeStatement::class)
        ->set('year', 2026)
        ->set('spread', IncomeStatement::SPREAD_MONTHLY)
        ->callAction('download_pdf')
        ->assertHasNoActionErrors();

    Filament::setTenant(null, isQuiet: true);
});

it('draws every column into the printed statement', function () {
    // And the template really renders them. `PdfDocument::html()` is the seam for exactly this: a
    // test that had to inflate a PDF's compressed streams to find out whether a column is on the page
    // would never be written, and the one written instead asserts on the inputs and proves nothing.
    spreadThreeMonths();

    $spread = app(StatementSpread::class)->incomeStatement(spreadMonths(['2026-01', '2026-02', '2026-03']));

    $html = PdfDocument::make('accounting.pdf.income-statement-spread')
        ->data([
            'spread' => $spread,
            'meta' => ['property' => 'A mall', 'period' => '2026', 'generated_on' => '01/01/2026', 'locale' => 'en'],
            ...IssuingEntity::forViewScopedTo(null),
        ])
        ->html();

    // One heading per month…
    expect($html)->toContain('Jan')->toContain('Feb')->toContain('Mar')
        // …the NOI line the whole statement is read for…
        ->toContain(__('admin.reports.net_operating_income'))
        // …and its three falling figures, which is the story the spread exists to tell.
        ->toContain('360,000.00')->toContain('320,000.00')->toContain('280,000.00');
});

it('exports the columns it displays', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    spreadThreeMonths($asset->id);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    $page = Livewire::test(IncomeStatement::class)
        ->set('year', 2026)
        ->set('period', '2026-03')
        ->set('spread', IncomeStatement::SPREAD_YTD)
        ->instance();

    $csv = $page->reportCsv();

    // Section, code, account, then one column per span — the same columns the screen draws.
    expect($csv['headers'])->toHaveCount(5)
        ->and($csv['headers'][4])->toBe(__('admin.reports.spread_period'));

    $noiRow = collect($csv['rows'])->first(fn (array $r): bool => $r[0] === __('admin.reports.net_operating_income'));

    expect($noiRow[3])->toBe(280_000.0)
        ->and($noiRow[4])->toBe(960_000.0);

    Filament::setTenant(null, isQuiet: true);
});

it('reads the month against its budget, and the year against its budget', function () {
    // Yardi's flagship income statement is month actual / budget / variance beside YTD actual /
    // budget / variance. That is the whole layout, and it was the one column path with no test —
    // found in review, not by a failure, because every OTHER basis exercises a different branch of
    // `comparisonReport()`: a budget compares against the group's OWN dates while a prior period
    // moves the span, so a green prior-period test says nothing about this.
    $asset = makeAsset();
    spreadThreeMonths($asset->id);

    $rent = LedgerAccount::where('code', '4100')->firstOrFail();

    // Planned 480,000 in March; the mall actually took 500,000.
    BudgetLine::create([
        'asset_id' => $asset->id, 'ledger_account_id' => $rent->id,
        'fiscal_year' => 2026, 'month' => 3, 'amount' => 480_000,
    ]);

    $spread = app(StatementSpread::class)->incomeStatement([
        ['key' => 'period', 'label' => 'Mar', 'from' => CarbonImmutable::parse('2026-03-01'), 'to' => CarbonImmutable::parse('2026-03-31')->endOfDay()],
        ['key' => 'ytd', 'label' => 'YTD', 'from' => CarbonImmutable::parse('2026-01-01'), 'to' => CarbonImmutable::parse('2026-03-31')->endOfDay()],
    ], [$asset->id], ComparativeStatementService::BUDGET);

    // Six columns: actual, budget and variance for each of the two groups.
    expect(array_column($spread['spans'], 'kind'))
        ->toBe(['actual', 'comparison', 'variance', 'actual', 'comparison', 'variance']);

    $revenue = $spread['totals']['operating_revenue'];

    expect($revenue['period'])->toBe(500_000.0)
        ->and($revenue['period'.StatementSpread::COMPARISON_SUFFIX])->toBe(480_000.0)
        ->and($revenue['period'.StatementSpread::VARIANCE_SUFFIX])->toBe(20_000.0);

    // The budget compares against the group's OWN dates, never a moved span — so the YTD column's
    // budget is the plan for Jan–Mar, which is that single March line and not three months of it.
    expect($revenue['ytd'])->toBe(1_500_000.0)
        ->and($revenue['ytd'.StatementSpread::COMPARISON_SUFFIX])->toBe(480_000.0);
});

it('agrees with every other reading of the same month', function () {
    // Three code paths now answer "what was March's NOI" — the plain statement, the comparative one
    // and the spread. They are built differently on purpose (the last two work from a UNION across
    // spans), which is exactly why they need pinning against each other: a spread that quietly
    // disagreed with the statement it sits on would be discovered by an operator, not by a test.
    $asset = makeAsset();
    spreadThreeMonths($asset->id);

    $from = CarbonImmutable::parse('2026-03-01');
    $to = CarbonImmutable::parse('2026-03-31')->endOfDay();

    $plain = app(LedgerReportService::class)->incomeStatement([$asset->id], $from, $to);
    $comparative = app(ComparativeStatementService::class)->incomeStatement($from, $to, [$asset->id], ComparativeStatementService::PRIOR_PERIOD);
    $spread = app(StatementSpread::class)->incomeStatement(
        [['key' => 'period', 'label' => 'Mar', 'from' => $from, 'to' => $to]],
        [$asset->id],
        ComparativeStatementService::PRIOR_PERIOD,
    );

    expect($plain['net_operating_income'])->toBe(280_000.0)
        ->and($comparative['totals']['noi']['current'])->toBe(280_000.0)
        ->and($spread['totals']['noi']['period'])->toBe(280_000.0)
        // …and on the comparison side too, which is the half a single-figure check would miss.
        ->and($comparative['totals']['noi']['prior'])->toBe($spread['totals']['noi']['period'.StatementSpread::COMPARISON_SUFFIX]);
});
