<?php

use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
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
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * ONE MONTH OF A CLOSED YEAR CANNOT BE REOPENED ON ITS OWN (SW-136).
 *
 * The year-end close posts, per property, the entry that zeroes the year's P&L into retained
 * earnings — computed ONCE, from `profitLossBalancesByAsset()` at close time — and then locks every
 * month. `close()` is idempotent, so re-closing rolls nothing new.
 *
 * The row action reopened a single month with no reference to any of that, and
 * `JournalPostingService` gates on the PERIOD's status and has never read the fiscal year's. So an
 * entry posted into the reopened month is outside retained earnings permanently: this year's close
 * cannot pick it up and next year's span does not cover its date. Nothing fails loudly, because the
 * balance sheet derives `net_income` from whatever P&L is left un-rolled and simply carries the
 * orphan for ever — under a year whose income statement no longer agrees with its equity.
 *
 * The escape already existed and the refusal names it: reopen the YEAR, which voids the closing
 * entry and unlocks every month. That path must keep working, so `YearEndCloseService::reopen()`
 * takes the FORCE twin — it relaxes the year-end period precisely in order to void the entry.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->post = app(JournalPostingService::class);
    $this->accounts = app(AccountResolver::class);
    $this->periods = app(PeriodService::class);
    $this->yearEnd = app(YearEndCloseService::class);
    $this->reports = app(LedgerReportService::class);

    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 1));

    $this->retainedEarnings = fn (): LedgerAccount => LedgerAccount::findOrFail(
        $this->accounts->id('retained_earnings'),
    );
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('reopens an ordinary closed month — the control', function () {
    // No closing entry anywhere, so nothing is at stake and the ordinary correction path stands.
    $this->periods->closePeriod($this->march);
    expect($this->march->fresh()->status)->toBe('closed');

    $this->periods->reopenPeriod($this->march->fresh());

    expect($this->march->fresh()->status)->toBe('open');
});

it('refuses to reopen one month while the year’s closing entry still stands', function () {
    $this->post->post(['entry_date' => '2026-03-05', 'lines' => [
        ['ledger_account_id' => $this->accounts->id('accounts_receivable'), 'debit' => 3000, 'credit' => 0],
        ['ledger_account_id' => $this->accounts->id('rent_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);

    $this->yearEnd->close(2026);
    $this->periods->closeFiscalYear(FiscalYear::where('year', 2026)->first());

    // The premise, asserted rather than assumed.
    expect($this->yearEnd->closingEntryFor(2026))->not->toBeNull()
        ->and($this->march->fresh()->status)->toBe('closed');

    expect(fn () => $this->periods->reopenPeriod($this->march->fresh()))
        ->toThrow(DomainException::class);

    expect($this->march->fresh()->status)->toBe('closed');
});

it('refuses it from the row action too, rather than 500ing or silently succeeding', function () {
    $this->post->post(['entry_date' => '2026-03-05', 'lines' => [
        ['ledger_account_id' => $this->accounts->id('accounts_receivable'), 'debit' => 3000, 'credit' => 0],
        ['ledger_account_id' => $this->accounts->id('rent_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);
    $this->yearEnd->close(2026);
    $this->periods->closeFiscalYear(FiscalYear::where('year', 2026)->first());

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$asset->id]));
    Filament::setTenant($asset);

    Livewire::test(ListAccountingPeriods::class)
        ->callTableAction('reopen_period', $this->march->fresh())
        ->assertHasNoTableActionErrors();

    expect($this->march->fresh()->status)->toBe('closed');
});

it('lets the correction through the YEAR — the escape the refusal names, and equity ends up right', function () {
    $this->post->post(['entry_date' => '2026-03-05', 'lines' => [
        ['ledger_account_id' => $this->accounts->id('accounts_receivable'), 'debit' => 3000, 'credit' => 0],
        ['ledger_account_id' => $this->accounts->id('rent_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);

    $this->yearEnd->close(2026);
    $this->periods->closeFiscalYear(FiscalYear::where('year', 2026)->first());

    expect($this->reports->accountLedger(($this->retainedEarnings)())['closing'])
        ->toEqualWithDelta(3000.0, 0.001);

    // Reopen the YEAR: unlock the months, then void the closing entries in-year.
    $this->periods->reopenFiscalYear(FiscalYear::where('year', 2026)->first());
    $this->yearEnd->reopen(2026);

    expect($this->yearEnd->closingEntryFor(2026))->toBeNull()
        ->and($this->march->fresh()->status)->toBe('open');

    // The correction the operator wanted to make in March all along.
    $this->post->post(['entry_date' => '2026-03-20', 'lines' => [
        ['ledger_account_id' => $this->accounts->id('salaries_expense'), 'debit' => 500, 'credit' => 0],
        ['ledger_account_id' => $this->accounts->id('bank'), 'debit' => 0, 'credit' => 500],
    ]]);

    $this->yearEnd->close(2026);

    // 3000 revenue less the 500 correction — the whole point: through the year, equity moves.
    expect($this->reports->accountLedger(($this->retainedEarnings)())['closing'])
        ->toEqualWithDelta(2500.0, 0.001);
});

it('still posts the year-end reversal back inside the closed year', function () {
    // `YearEndCloseService::reopen()` relaxes the year-end period ITSELF, and it must keep doing so
    // — the guard above would otherwise refuse the one caller that is relaxing the period in order
    // to void the very entry the guard is protecting.
    $this->post->post(['entry_date' => '2026-03-05', 'lines' => [
        ['ledger_account_id' => $this->accounts->id('accounts_receivable'), 'debit' => 3000, 'credit' => 0],
        ['ledger_account_id' => $this->accounts->id('rent_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);
    $this->yearEnd->close(2026);
    $this->periods->closeFiscalYear(FiscalYear::where('year', 2026)->first());

    $this->yearEnd->reopen(2026);

    $reversal = JournalEntry::whereNotNull('reversal_of_id')
        ->where('is_closing', true)
        ->latest('id')
        ->first();

    expect($reversal)->not->toBeNull()
        ->and(Carbon::parse($reversal->entry_date)->year)->toBe(2026)
        ->and($this->reports->accountLedger(($this->retainedEarnings)())['closing'])
        ->toEqualWithDelta(0.0, 0.001);
});
