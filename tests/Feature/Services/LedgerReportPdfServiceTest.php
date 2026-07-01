<?php

use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportPdfService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $r = app(AccountResolver::class);
    app(JournalPostingService::class)->post(['entry_date' => '2026-03-01', 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 3000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);
    app(JournalPostingService::class)->post(['entry_date' => '2026-03-10', 'lines' => [
        ['ledger_account_id' => $r->id('salaries_expense'), 'debit' => 1200, 'credit' => 0],
        ['ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 1200],
    ]]);

    $this->svc = app(LedgerReportPdfService::class);
    $this->from = CarbonImmutable::create(2026, 1, 1);
    $this->to = CarbonImmutable::create(2026, 12, 31);
});

it('renders each financial statement to a valid PDF', function () {
    expect($this->svc->trialBalance(null, $this->from, $this->to, 'Consolidated', 2026))->toStartWith('%PDF');
    expect($this->svc->incomeStatement(null, $this->from, $this->to, 'Consolidated', 2026))->toStartWith('%PDF');
    expect($this->svc->balanceSheet(null, $this->to, 'Consolidated'))->toStartWith('%PDF');
});

it('renders the Arabic (RTL) statements without error', function () {
    app()->setLocale('ar');

    expect($this->svc->trialBalance(null, $this->from, $this->to, 'موحّد', 2026))->toStartWith('%PDF');
    expect($this->svc->incomeStatement(null, $this->from, $this->to, 'موحّد', 2026))->toStartWith('%PDF');
    expect($this->svc->balanceSheet(null, $this->to, 'موحّد'))->toStartWith('%PDF');
});
