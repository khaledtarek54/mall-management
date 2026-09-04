<?php

use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\StatementIntegrity;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **An exported statement foots, and says whether it balances** (SW-182).
 *
 * All three statements lead their screen subheading with an integrity check and both PDFs print it;
 * none of the three CSVs did, and the balance sheet's export dropped `total_equity_and_liabilities`
 * — the figure `Total assets` has to equal — as well. Measured 2026-09-04 against
 * `mall_management_qa`: the exported balance sheet ended on `,,Net income,…`, so the file an owner
 * or an auditor receives could not be checked at all by the reader least able to open the ledger.
 *
 * Each check is paired with the OPPOSITE case rather than only the happy one, because a wording that
 * always printed ✓ would satisfy every positive assertion on its own.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $r = app(AccountResolver::class);
    $post = app(JournalPostingService::class);

    $this->reports = app(LedgerReportService::class);
    $this->exporter = app(ReportCsvExporter::class);
    $this->asset = makeAsset();

    // Rent 10,000 and salaries 4,000 in March: assets 6,000, net income 6,000, and it balances.
    $post->post(['entry_date' => '2026-03-10', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 10000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 10000],
    ]]);
    $post->post(['entry_date' => '2026-03-12', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('salaries_expense'), 'debit' => 4000, 'credit' => 0],
        ['ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 4000],
    ]]);

    $this->from = CarbonImmutable::create(2026, 1, 1)->startOfDay();
    $this->to = CarbonImmutable::create(2026, 12, 31)->endOfDay();
});

it('foots the exported balance sheet against total assets', function () {
    $report = $this->reports->balanceSheet([$this->asset->id], $this->to);
    $csv = $this->exporter->balanceSheet($report);

    $index = array_search(__('admin.reports.total_equity_and_liabilities'), array_column($csv['rows'], 2), true);

    expect($index)->not->toBeFalse()
        // The FIGURE, not only the label — it is what `Total assets` has to equal.
        ->and((float) $csv['rows'][$index][3])->toBe(round((float) $report['total_equity_and_liabilities'], 2))
        ->and((float) $csv['rows'][$index][3])->toBe(round((float) $report['total_assets'], 2))
        // …and it is not zero, or every assertion above would hold on an empty export.
        ->and((float) $csv['rows'][$index][3])->toBeGreaterThan(0.0)
        // The control: the sections the export always carried are still there.
        ->and(collect($csv['rows'])->pluck(0))->toContain(__('admin.reports.csv.assets'));
});

it('says on the exported balance sheet whether it balances', function () {
    $csv = $this->exporter->balanceSheet($this->reports->balanceSheet([$this->asset->id], $this->to));

    // The opposite case, hand-built: a sheet with assets and nothing on the other side.
    $broken = $this->exporter->balanceSheet([
        'assets' => collect(), 'total_assets' => 5000.0,
        'liabilities' => collect(), 'total_liabilities' => 0.0,
        'equity' => collect(), 'total_equity' => 0.0,
        'net_income' => 0.0,
        'total_equity_and_liabilities' => 0.0,
        'balanced' => false,
    ]);

    expect(end($csv['rows'])[2])->toBe(StatementIntegrity::balance(true))
        ->and(end($broken['rows'])[2])->toBe(StatementIntegrity::balance(false))
        // Without this the wording could answer ✓ to everything and still pass.
        ->and(end($broken['rows'])[2])->not->toBe(end($csv['rows'])[2]);
});

it('says on the exported trial balance whether it balances, after the totals line', function () {
    $csv = $this->exporter->trialBalance($this->reports->trialBalance([$this->asset->id], $this->from, $this->to));
    $rows = $csv['rows'];
    $totals = $rows[count($rows) - 2];

    expect($csv['headers'])->toHaveCount(5)
        // The check is a row AFTER the totals line, not instead of it.
        ->and((float) $totals[3])->toBe((float) $totals[4])
        ->and((float) $totals[3])->toBeGreaterThan(0.0)
        ->and(end($rows)[1])->toBe(StatementIntegrity::balance(true));
});

it('says on the exported cash-flow statement whether it reconciles', function () {
    $base = $this->reports->cashFlow([$this->asset->id], $this->from, $this->to);

    // Both readings off ONE computed report, so the assertion cannot depend on whether this
    // fixture's books happen to reconcile.
    $ok = $this->exporter->cashFlow(['reconciled' => true] + $base);
    $bad = $this->exporter->cashFlow(['reconciled' => false] + $base);

    expect(end($ok['rows'])[2])->toBe(StatementIntegrity::cashFlow(true))
        ->and(end($bad['rows'])[2])->toBe(StatementIntegrity::cashFlow(false))
        ->and(end($ok['rows'])[2])->not->toBe(end($bad['rows'])[2]);
});
