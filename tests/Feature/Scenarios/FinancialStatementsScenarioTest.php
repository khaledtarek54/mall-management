<?php

use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->post = app(JournalPostingService::class);
    $this->r = app(AccountResolver::class);
    $this->reports = app(LedgerReportService::class);
});

/** Post a two-line entry by semantic role. */
function glEntry(AccountResolver $r, JournalPostingService $post, string $debitRole, string $creditRole, float $amount, ?int $assetId = null): void
{
    $post->post([
        'entry_date' => now()->toDateString(),
        'asset_id' => $assetId,
        'lines' => [
            ['ledger_account_id' => $r->id($debitRole, $assetId), 'debit' => $amount, 'credit' => 0],
            ['ledger_account_id' => $r->id($creditRole, $assetId), 'debit' => 0, 'credit' => $amount],
        ],
    ]);
}

it('computes the income statement: revenue − expense = net profit', function () {
    glEntry($this->r, $this->post, 'accounts_receivable', 'rent_revenue', 1000);
    glEntry($this->r, $this->post, 'accounts_receivable', 'service_charge_revenue', 500);
    glEntry($this->r, $this->post, 'salaries_expense', 'bank', 300);

    $is = $this->reports->incomeStatement();

    expect($is['total_revenue'])->toEqualWithDelta(1500.0, 0.001);
    expect($is['total_expense'])->toEqualWithDelta(300.0, 0.001);
    expect($is['net_profit'])->toEqualWithDelta(1200.0, 0.001);
});

it('nets contra-revenue (sales returns) against revenue', function () {
    glEntry($this->r, $this->post, 'accounts_receivable', 'rent_revenue', 1000);
    glEntry($this->r, $this->post, 'sales_returns', 'accounts_receivable', 200); // a credit-note style return

    $is = $this->reports->incomeStatement();

    // sales_returns is a contra-revenue (net debit) → reduces total revenue.
    expect($is['total_revenue'])->toEqualWithDelta(800.0, 0.001);
});

it('produces a balanced balance sheet (Assets = Liabilities + Equity + Net income)', function () {
    glEntry($this->r, $this->post, 'bank', 'capital', 5000);          // owner injects capital
    glEntry($this->r, $this->post, 'accounts_receivable', 'rent_revenue', 1000); // earn revenue
    glEntry($this->r, $this->post, 'salaries_expense', 'bank', 300);  // pay an expense

    $bs = $this->reports->balanceSheet();

    expect($bs['total_assets'])->toEqualWithDelta(5700.0, 0.001);     // bank 4700 + AR 1000
    expect($bs['total_equity'])->toEqualWithDelta(5000.0, 0.001);     // capital
    expect($bs['net_income'])->toEqualWithDelta(700.0, 0.001);        // 1000 − 300
    expect($bs['total_equity_and_liabilities'])->toEqualWithDelta(5700.0, 0.001);
    expect($bs['balanced'])->toBeTrue();
});

it('scopes the statements per property', function () {
    $a = makeAsset();
    $b = makeAsset();
    glEntry($this->r, $this->post, 'accounts_receivable', 'rent_revenue', 1000, $a->id);
    glEntry($this->r, $this->post, 'accounts_receivable', 'rent_revenue', 400, $b->id);

    expect($this->reports->incomeStatement([$a->id])['total_revenue'])->toEqualWithDelta(1000.0, 0.001);
    expect($this->reports->incomeStatement([$b->id])['total_revenue'])->toEqualWithDelta(400.0, 0.001);
    expect($this->reports->incomeStatement()['total_revenue'])->toEqualWithDelta(1400.0, 0.001);
});
