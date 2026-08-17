<?php

use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\CashFlow;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Models\FiscalYear;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * The operator runs a MONTHLY close and could not print that month's statements.
 *
 * `ScopesLedgerReport` hardcoded `Carbon::create($year, 1, 1)` to `Carbon::create($year, 12, 31)`,
 * and the balance sheet was always as-of 31 December. Every ledger page — trial balance, income
 * statement, balance sheet, cash flow, general ledger — was calendar-year only. **The underlying
 * services already took ranges**; only the pages did not, which is what made this a filter and not
 * a rebuild.
 *
 * Second defect in the same six lines: `FiscalYear` carries `starts_on`/`ends_on` and these pages
 * ignored them, so an operator on a non-calendar year — an April→March mall year is ordinary in
 * Egypt — got a report for the wrong twelve months, silently, because the header only ever said
 * the year number.
 *
 * The period reaches the PDF header and the export filename as well as the screen, so a March
 * trial balance cannot land on disk under a name that reads like the whole year's.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
});

/** Reach the trait's protected period methods the way the pages do. */
function ledgerPeriod(object $page, string $method): mixed
{
    $m = new ReflectionMethod($page::class, $method);
    $m->setAccessible(true);

    return $m->invoke($page);
}

it('defaults to the whole year, exactly as before', function () {
    $page = new TrialBalance;
    $page->year = 2026;
    $page->period = null;

    expect(ledgerPeriod($page, 'periodStart')->toDateString())->toBe('2026-01-01')
        ->and(ledgerPeriod($page, 'periodEnd')->toDateString())->toBe('2026-12-31');
});

it('narrows to a single month when one is picked', function () {
    $page = new TrialBalance;
    $page->year = 2026;
    $page->period = '2026-03';

    expect(ledgerPeriod($page, 'periodStart')->toDateString())->toBe('2026-03-01')
        ->and(ledgerPeriod($page, 'periodEnd')->toDateString())->toBe('2026-03-31');
});

it('follows a NON-CALENDAR fiscal year instead of assuming January to December', function () {
    // An April→March mall year. Before this the page reported Jan–Dec 2026 and said "2026" — the
    // wrong twelve months, with nothing on the page to reveal it.
    FiscalYear::create([
        'year' => 2026,
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
        'status' => 'open',
    ]);

    $page = new IncomeStatement;
    $page->year = 2026;
    $page->period = null;

    expect(ledgerPeriod($page, 'periodStart')->toDateString())->toBe('2026-04-01')
        ->and(ledgerPeriod($page, 'periodEnd')->toDateString())->toBe('2027-03-31');
});

it('offers the months of THAT fiscal year, labelled with their real calendar year', function () {
    FiscalYear::create([
        'year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'open',
    ]);

    $page = new TrialBalance;
    $page->year = 2026;

    $options = ledgerPeriod($page, 'periodOptions');

    // Twelve months, first April 2026, last March 2027. "Month 12" would be actively misleading
    // here — it is in the NEXT calendar year.
    expect($options)->toHaveCount(12)
        ->and(array_key_first($options))->toBe('2026-04')
        ->and(array_key_last($options))->toBe('2027-03');
});

it('falls back to the calendar year when no fiscal year row exists', function () {
    // What a fresh install looks like before the accountant sets one up.
    $page = new TrialBalance;
    $page->year = 2026;

    expect(ledgerPeriod($page, 'periodStart')->toDateString())->toBe('2026-01-01')
        ->and(ledgerPeriod($page, 'periodOptions'))->toHaveCount(12);
});

it('dates the balance sheet AS OF the chosen month end, not 31 December', function () {
    // The balance sheet's whole meaning is its as-of date; pinned to year-end it could not answer
    // "what did we hold at the end of March?" — the question a monthly close asks.
    $page = new BalanceSheet;
    $page->year = 2026;
    $page->period = '2026-03';

    expect(ledgerPeriod($page, 'periodEnd')->toDateString())->toBe('2026-03-31');
});

it('carries the month into the export filename and the PDF header', function () {
    $page = new TrialBalance;
    $page->year = 2026;
    $page->period = '2026-03';

    expect(ledgerPeriod($page, 'periodSlug'))->toBe('2026-03')
        ->and(ledgerPeriod($page, 'periodLabel'))->toContain('2026');

    $page->period = null;

    // The year-only case must stay exactly as it was, or every existing export changes name.
    expect(ledgerPeriod($page, 'periodSlug'))->toBe('2026')
        ->and(ledgerPeriod($page, 'periodLabel'))->toBe('2026');
});

it('clears the month when the year changes', function () {
    // Livewire keeps `$period` across the update. Without the reset, picking 2025 while March 2026
    // was selected leaves a report headed 2025 showing March 2026 — the two pickers contradicting
    // each other, which is worse than either being wrong alone.
    Livewire::test(TrialBalance::class)
        ->set('period', '2026-03')
        ->set('year', 2025)
        ->assertSet('period', null);
});

it('renders every ledger page for a single month', function (string $page) {
    // The pages are the thing that was broken, so drive them rather than only the trait.
    Livewire::test($page)
        ->set('year', (int) now()->year)
        ->set('period', now()->format('Y-m'))
        ->assertOk();
})->with([
    TrialBalance::class,
    IncomeStatement::class,
    BalanceSheet::class,
    CashFlow::class,
]);
