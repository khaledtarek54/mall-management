<?php

use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Pages\WithholdingTaxReturn;
use App\Services\Accounting\FiscalCalendar;
use App\Settings\AccountingSettings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-131 / SW-209 — a Form 41 quarter could not survive a link or a saved view.
 *
 * `ScopesLedgerReport::hydrateLedgerScopeFromQuery()` validated the URL's `period` against
 * `/^\d{4}-\d{2}$/`. Every ledger report's period is a month — **except the one whose period is a
 * QUARTER**: Egypt files withholding tax on Form 41 quarterly, and `WithholdingTaxReturn` offers
 * `2026-Q1`. So its own drill-down link, and every saved view of it, arrived and was thrown away,
 * and the screen opened on the full YEAR.
 *
 * The tell is the worst kind: no error, and a *plausible* report. The emailed CSV never passes
 * through this method, so the scheduled copy still carried the quarter — one report, two periods,
 * and the only way to notice is that the figures do not match.
 *
 * The shape was written twice — once in the picker that offers periods, once in the guard that
 * accepts them — which is this codebase's most repeated defect. The guard now derives from the
 * page's own `periodOptions()`, so a report with a new period shape needs no edit here.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->year = (int) now()->year;
    app(FiscalCalendar::class)->ensureYear($this->year);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Open a page the way a link does — through the query string, which is what `mount()` reads. */
function openWithQuery(string $page, array $query): object
{
    return Livewire::withQueryParams($query)->test($page);
}

it('keeps the quarter a Form 41 link is carrying', function () {
    $quarter = $this->year.'-Q2';

    // Measured before the fix: null — the page opened on the whole fiscal year, while the emailed
    // CSV of the same saved view carried Q2.
    openWithQuery(WithholdingTaxReturn::class, ['year' => $this->year, 'period' => $quarter])
        ->assertSet('period', $quarter);
});

it('still keeps a month, which is what every other statement carries', function () {
    // The control. Deriving the accepted set from `periodOptions()` must not narrow the common case
    // — a report whose picker offers months has to keep accepting one.
    $month = $this->year.'-'.str_pad((string) now()->month, 2, '0', STR_PAD_LEFT);

    openWithQuery(IncomeStatement::class, ['year' => $this->year, 'period' => $month])
        ->assertSet('period', $month);

    // …and the quarterly page offers months too, so its own month links keep working.
    openWithQuery(WithholdingTaxReturn::class, ['year' => $this->year, 'period' => $month])
        ->assertSet('period', $month);
});

it('refuses a period the page does not offer', function () {
    // Still a guard, not a pass-through. A quarter is meaningless on an income statement — it has no
    // such option — and a crafted value must not reach `selectedMonth()`'s parser.
    openWithQuery(IncomeStatement::class, ['year' => $this->year, 'period' => $this->year.'-Q2'])
        ->assertSet('period', null);

    openWithQuery(TrialBalance::class, ['year' => $this->year, 'period' => '2026-13'])
        ->assertSet('period', null);

    openWithQuery(TrialBalance::class, ['year' => $this->year, 'period' => 'not-a-period'])
        ->assertSet('period', null);
});

it('finds the fiscal year a bare period belongs to, on an April year', function () {
    // **The case the first fix got backwards, and the one this operator actually runs.** Egypt's
    // April→March year is settable and supported, so FY2026 runs Apr 2026 – Mar 2027 and
    // `periodOptions()` legitimately offers `2027-01`, `2027-02`, `2027-03`.
    //
    // Reading the year off the period's first four digits — the obvious shortcut — moves the page to
    // FY2027 (Apr 2027 – Mar 2028), where `2027-01` is not a member. The period is then discarded
    // AND the year has moved, so the report opens on a completely different twelve months with
    // nothing on screen to say so. Measured: it made the very case it was added for worse than
    // before it existed. The year is SEARCHED for instead.
    app(AccountingSettings::class)->fill(['fiscal_year_start_month' => 4])->save();

    app(FiscalCalendar::class)->ensureYear($this->year);
    app(FiscalCalendar::class)->ensureYear($this->year + 1);

    $tailMonth = ($this->year + 1).'-01';   // January, which belongs to the year that OPENED in April

    openWithQuery(IncomeStatement::class, ['period' => $tailMonth])
        ->assertSet('year', $this->year)
        ->assertSet('period', $tailMonth);
});

it('takes the year from the period when the link names only one', function () {
    // `periodOptions()` is built from `$this->year`, so a period from another year would fail the
    // membership test and be silently discarded. A saved view always carries both
    // (`ReportParameters::urlFor()` writes every declared property), but a hand-trimmed link may
    // not — and losing the period there would open a different report with no sign of it.
    $lastYear = $this->year - 1;
    app(FiscalCalendar::class)->ensureYear($lastYear);

    openWithQuery(WithholdingTaxReturn::class, ['period' => $lastYear.'-Q3'])
        ->assertSet('year', $lastYear)
        ->assertSet('period', $lastYear.'-Q3');
});
