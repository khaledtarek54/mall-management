<?php

use App\Models\AccountingPeriod;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\PeriodService;
use App\Services\Accounting\YearEndCloseService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->post = app(JournalPostingService::class);
    $this->r = app(AccountResolver::class);
    $this->reports = app(LedgerReportService::class);
    $this->from = CarbonImmutable::create(2026, 1, 1);
    $this->to = CarbonImmutable::create(2026, 12, 31);
});

/** Post revenue 3000 + expense 1200 in 2026 → net profit 1800. */
function seedYearPandL(JournalPostingService $post, AccountResolver $r): void
{
    $post->post(['entry_date' => '2026-03-05', 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 3000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);
    $post->post(['entry_date' => '2026-03-10', 'lines' => [
        ['ledger_account_id' => $r->id('salaries_expense'), 'debit' => 1200, 'credit' => 0],
        ['ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 1200],
    ]]);
}

it('closes the year: zeroes P&L into retained earnings, income statement unchanged', function () {
    seedYearPandL($this->post, $this->r);

    $before = $this->reports->incomeStatement(null, $this->from, $this->to);
    expect($before['net_profit'])->toEqualWithDelta(1800.0, 0.001);

    $entry = app(YearEndCloseService::class)->close(2026);

    expect($entry)->not->toBeNull();
    expect($entry->is_closing)->toBeTrue();
    expect($entry->isBalanced())->toBeTrue();

    // Income statement must show the ACTUAL P&L (closing entry excluded).
    $after = $this->reports->incomeStatement(null, $this->from, $this->to);
    expect($after['net_profit'])->toEqualWithDelta(1800.0, 0.001);

    // Retained earnings now carries the net profit.
    $re = LedgerAccount::where('code', '32101001')->first();
    expect($this->reports->accountLedger($re)['closing'])->toEqualWithDelta(1800.0, 0.001);

    // Trial balance still balances; balance sheet's period net-income is now 0 (rolled to equity).
    expect($this->reports->trialBalance(null, $this->from, $this->to)['balanced'])->toBeTrue();
    $bs = $this->reports->balanceSheet(null, $this->to);
    expect($bs['balanced'])->toBeTrue();
    expect($bs['net_income'])->toEqualWithDelta(0.0, 0.001);
});

it('is idempotent — closing an already-closed year returns the same entry', function () {
    seedYearPandL($this->post, $this->r);

    $first = app(YearEndCloseService::class)->close(2026);
    $second = app(YearEndCloseService::class)->close(2026);

    expect($second->id)->toBe($first->id);
});

it('reopen voids the closing entry and keeps it out of the income statement', function () {
    seedYearPandL($this->post, $this->r);
    $svc = app(YearEndCloseService::class);
    $svc->close(2026);

    $svc->reopen(2026);

    // The reversal inherits is_closing, so the P&L still shows the actual 1800.
    expect($this->reports->incomeStatement(null, $this->from, $this->to)['net_profit'])->toEqualWithDelta(1800.0, 0.001);
    // Year is reopenable to close again.
    expect($svc->closingEntryFor(2026))->toBeNull();
    // Retained earnings back to zero after the reversal.
    $re = LedgerAccount::where('code', '32101001')->first();
    expect($this->reports->accountLedger($re)['closing'])->toEqualWithDelta(0.0, 0.001);
});

it('returns null when there is nothing to close', function () {
    expect(app(YearEndCloseService::class)->close(2026))->toBeNull();
});

it('closes and reopens a whole fiscal year (locks then unlocks its periods)', function () {
    $svc = app(PeriodService::class);
    $fy = \App\Models\FiscalYear::where('year', 2026)->first();

    $svc->closeFiscalYear($fy);
    expect($fy->fresh()->status)->toBe('closed');
    expect(AccountingPeriod::where('fiscal_year_id', $fy->id)->where('status', 'open')->count())->toBe(0);

    $svc->reopenFiscalYear($fy);
    expect($fy->fresh()->status)->toBe('open');
    expect(AccountingPeriod::where('fiscal_year_id', $fy->id)->where('status', 'closed')->count())->toBe(0);
});

it('reopen posts the reversal in-year even when the December period is locked', function () {
    seedYearPandL($this->post, $this->r);
    $ye = app(YearEndCloseService::class);
    $ye->close(2026);

    // Lock the whole year (December included).
    app(PeriodService::class)->closeFiscalYear(\App\Models\FiscalYear::where('year', 2026)->first());

    // Reopen must relax the period so the reversal posts back inside 2026.
    $ye->reopen(2026);

    expect($ye->closingEntryFor(2026))->toBeNull();
    $re = LedgerAccount::where('code', '32101001')->first();
    expect($this->reports->accountLedger($re)['closing'])->toEqualWithDelta(0.0, 0.001);
    $reversal = \App\Models\JournalEntry::whereNotNull('reversal_of_id')->where('is_closing', true)->latest('id')->first();
    expect(\Carbon\Carbon::parse($reversal->entry_date)->year)->toBe(2026);
});

it('closing a period blocks posting into it', function () {
    $period = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 1));
    app(PeriodService::class)->closePeriod($period);

    expect(fn () => $this->post->post(['entry_date' => '2026-03-15', 'lines' => [
        ['ledger_account_id' => $this->r->id('bank'), 'debit' => 100, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 100],
    ]]))->toThrow(DomainException::class, 'closed');

    // Reopening allows posting again.
    app(PeriodService::class)->reopenPeriod($period->fresh());
    $entry = $this->post->post(['entry_date' => '2026-03-15', 'lines' => [
        ['ledger_account_id' => $this->r->id('bank'), 'debit' => 100, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 100],
    ]]);
    expect($entry->status)->toBe('posted');
});
