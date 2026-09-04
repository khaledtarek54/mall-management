<?php

use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\ReportCsv;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Financial reports export to CSV (module 17). The app was PDF-only — a report an accountant can't
 * pull into a spreadsheet (pivot, reconcile, hand to an auditor) isn't a working report; the General
 * Ledger and AR aging had no export at all. These pin the row shape each report flattens to, and that
 * the stream carries a UTF-8 BOM (so Excel renders Arabic).
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->post = app(JournalPostingService::class);
    $this->r = app(AccountResolver::class);
    $this->reports = app(LedgerReportService::class);
    $this->exporter = app(ReportCsvExporter::class);
    $this->asset = makeAsset();

    // Rent 10,000 + salaries 4,000 in March.
    $this->post->post(['entry_date' => '2026-03-10', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 10000, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 10000],
    ]]);
    $this->post->post(['entry_date' => '2026-03-12', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('salaries_expense'), 'debit' => 4000, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('bank'), 'debit' => 0, 'credit' => 4000],
    ]]);

    $this->from = CarbonImmutable::create(2026, 1, 1)->startOfDay();
    $this->to = CarbonImmutable::create(2026, 12, 31)->endOfDay();
});

it('flattens the trial balance to rows that self-balance', function () {
    $report = $this->reports->trialBalance([$this->asset->id], $this->from, $this->to);
    $csv = $this->exporter->trialBalance($report);

    expect($csv['headers'])->toHaveCount(5);

    // The totals line — followed since SW-182 by the ✓/✗ the screen and the PDF both carry, so
    // `end()` no longer finds it. Located by its own label rather than by position, which is what
    // made this assertion fragile in the first place.
    $totals = collect($csv['rows'])->first(fn (array $row): bool => $row[1] === __('admin.reports.csv.total'));
    expect((float) $totals[3])->toBe((float) $totals[4])
        ->and((float) $totals[3])->toBeGreaterThan(0.0)
        // Every data row carries an account code + numeric debit/credit.
        ->and($csv['rows'][0][0])->not->toBeEmpty();
});

it('flattens the income statement into sections summing to the net', function () {
    $report = $this->reports->incomeStatement([$this->asset->id], $this->from, $this->to);
    $csv = $this->exporter->incomeStatement($report);

    // Net line last; equals total revenue − total expense (10,000 − 4,000 = 6,000).
    $net = end($csv['rows']);
    expect((float) $net[3])->toBe(6000.0);

    // A revenue line and an expense line are present, labelled by section.
    $sections = collect($csv['rows'])->pluck(0)->unique()->values();
    expect($sections)->toContain(__('admin.reports.csv.revenue'))
        ->toContain(__('admin.reports.csv.expenses'));
});

it('exports the general ledger for one account with a running balance', function () {
    $account = LedgerAccount::where('code', $this->r->account('rent_revenue')->code)->first();
    $statement = $this->reports->accountLedger($account, [$this->asset->id], $this->from, $this->to);
    $csv = $this->exporter->generalLedger($statement);

    // Opening line first, closing line last, one movement in between (the 10,000 credit).
    expect($csv['headers'])->toHaveCount(6)
        ->and($csv['rows'][0][2])->toBe(__('admin.reports.csv.opening_balance'))
        ->and(end($csv['rows'])[2])->toBe(__('admin.reports.csv.closing_balance'))
        // The movement row carries the entry date + a non-zero credit.
        ->and((float) $csv['rows'][1][4])->toBe(10000.0);
});

it('streams a CSV with a UTF-8 BOM so Excel renders Arabic', function () {
    app()->setLocale('ar');
    $report = $this->reports->trialBalance([$this->asset->id], $this->from, $this->to);
    $csv = $this->exporter->trialBalance($report);

    $response = ReportCsv::stream('trial-balance-2026', $csv['headers'], $csv['rows']);
    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toStartWith("\xEF\xBB\xBF")                       // the BOM
        ->and($response->headers->get('Content-Type'))->toContain('text/csv')
        // Arabic header rendered (no raw i18n key), inside the CSV body.
        ->and($body)->toContain(__('admin.reports.csv.account'));
});
