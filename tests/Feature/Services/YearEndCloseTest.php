<?php

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\PeriodService;
use App\Services\Accounting\YearEndCloseService;
use Carbon\Carbon;
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

    // All P&L here is posted without an asset_id → a single consolidated closing entry.
    $entries = app(YearEndCloseService::class)->close(2026);
    expect($entries)->toHaveCount(1);
    $entry = $entries->first();

    expect($entry->is_closing)->toBeTrue();
    expect($entry->asset_id)->toBeNull();
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

it('is idempotent — closing an already-closed year returns the same entries', function () {
    seedYearPandL($this->post, $this->r);

    $first = app(YearEndCloseService::class)->close(2026);
    $second = app(YearEndCloseService::class)->close(2026);

    expect($second->pluck('id')->all())->toBe($first->pluck('id')->all());
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

it('returns an empty collection when there is nothing to close', function () {
    expect(app(YearEndCloseService::class)->close(2026))->toBeEmpty();
});

it('closes per property: each property\'s net rolls into ITS OWN retained earnings (F-80)', function () {
    $assetA = makeAsset();
    $assetB = makeAsset();

    // Property A: revenue 1000 − expense 400 = profit 600
    $this->post->post(['entry_date' => '2026-03-05', 'asset_id' => $assetA->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 1000, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 1000],
    ]]);
    $this->post->post(['entry_date' => '2026-03-06', 'asset_id' => $assetA->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('salaries_expense'), 'debit' => 400, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('bank'), 'debit' => 0, 'credit' => 400],
    ]]);
    // Property B: revenue 500 − expense 200 = profit 300
    $this->post->post(['entry_date' => '2026-03-07', 'asset_id' => $assetB->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 500, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 500],
    ]]);
    $this->post->post(['entry_date' => '2026-03-08', 'asset_id' => $assetB->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('salaries_expense'), 'debit' => 200, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('bank'), 'debit' => 0, 'credit' => 200],
    ]]);

    $entries = app(YearEndCloseService::class)->close(2026);

    // One closing entry per property, each dimensioned to its own asset.
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('asset_id')->sort()->values()->all())->toBe([$assetA->id, $assetB->id])
        ->and($entries->every(fn ($e) => $e->is_closing && $e->isBalanced()))->toBeTrue();

    // The F-80 fix: property A's balance sheet rolls its OWN 600 into retained earnings.
    $bsA = $this->reports->balanceSheet([$assetA->id], $this->to);
    expect($bsA['equity']->firstWhere('code', '32101001')['amount'])->toEqualWithDelta(600.0, 0.001)
        ->and($bsA['net_income'])->toEqualWithDelta(0.0, 0.001)
        ->and($bsA['balanced'])->toBeTrue();

    $bsB = $this->reports->balanceSheet([$assetB->id], $this->to);
    expect($bsB['equity']->firstWhere('code', '32101001')['amount'])->toEqualWithDelta(300.0, 0.001);

    // Consolidated is still correct: retained earnings = 900, and the books balance.
    $bsAll = $this->reports->balanceSheet(null, $this->to);
    expect($bsAll['equity']->firstWhere('code', '32101001')['amount'])->toEqualWithDelta(900.0, 0.001)
        ->and($bsAll['balanced'])->toBeTrue();
    expect($this->reports->trialBalance(null, $this->from, $this->to)['balanced'])->toBeTrue();

    // Per-property income statements still show the actual P&L (closing excluded).
    expect($this->reports->incomeStatement([$assetA->id], $this->from, $this->to)['net_profit'])->toEqualWithDelta(600.0, 0.001);
});

it('closes and reopens a whole fiscal year (locks then unlocks its periods)', function () {
    $svc = app(PeriodService::class);
    $fy = FiscalYear::where('year', 2026)->first();

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
    app(PeriodService::class)->closeFiscalYear(FiscalYear::where('year', 2026)->first());

    // Reopen must relax the period so the reversal posts back inside 2026.
    $ye->reopen(2026);

    expect($ye->closingEntryFor(2026))->toBeNull();
    $re = LedgerAccount::where('code', '32101001')->first();
    expect($this->reports->accountLedger($re)['closing'])->toEqualWithDelta(0.0, 0.001);
    $reversal = JournalEntry::whereNotNull('reversal_of_id')->where('is_closing', true)->latest('id')->first();
    expect(Carbon::parse($reversal->entry_date)->year)->toBe(2026);
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

it('closes a loss year: debits retained earnings (equity down)', function () {
    // revenue 500, expense 2000 → net loss 1500
    $this->post->post(['entry_date' => '2026-04-01', 'lines' => [
        ['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 500, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 500],
    ]]);
    $this->post->post(['entry_date' => '2026-04-02', 'lines' => [
        ['ledger_account_id' => $this->r->id('salaries_expense'), 'debit' => 2000, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('bank'), 'debit' => 0, 'credit' => 2000],
    ]]);

    app(YearEndCloseService::class)->close(2026);

    // Retained earnings (equity, credit-normal) is DEBITED by the loss → net debit → closing -1500.
    $re = LedgerAccount::where('code', '32101001')->first();
    $st = $this->reports->accountLedger($re, null, $this->from, $this->to);
    expect($st['closing'])->toEqualWithDelta(-1500.0, 0.001);

    // Post-close income statement still shows the actual loss (closing entries excluded).
    expect($this->reports->incomeStatement(null, $this->from, $this->to)['net_profit'])->toEqualWithDelta(-1500.0, 0.001);
});

it('close() is self-sufficient — ensures the fiscal year row so its lock binds', function () {
    // A year never opened: close() must still create the fiscal-year row (so the
    // double-close lock has a row to hold) and return null when there is nothing to close.
    expect(FiscalYear::where('year', 2035)->exists())->toBeFalse();

    expect(app(YearEndCloseService::class)->close(2035))->toBeEmpty();

    expect(FiscalYear::where('year', 2035)->exists())->toBeTrue();
});
