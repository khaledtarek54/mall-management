<?php

use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Pages\WithholdingTaxReturn;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * SW-224 — a thirteenth month rendered a confident report for a different one.
 *
 * SW-131 taught `mount()` to validate `?period=` against the page's own `periodOptions()`. It closed
 * the URL door and only the URL door: `$period` is a PUBLIC Livewire property, so a `$wire.set()` in
 * any later request reaches all seventeen readers without passing through `mount()`, and
 * `ReportParameters::apply()` writes it directly on the scheduled-delivery path, which does not call
 * it either.
 *
 * What got through was not a parse error. `selectedMonth()` tested `\d{4}-\d{2}`, and Carbon does not
 * throw on a thirteenth month — measured at HEAD and pinned below: `2026-13-01` OVERFLOWS to
 * 2027-01-01, `2026-99` to 2034-03-01, `2026-00` back to 2025-12-01. So the trial balance, income
 * statement, balance sheet and cash flow all ran for a month nobody asked for, headed "January 2027",
 * beside a period picker showing its own "Full year" placeholder because it cannot label a value it
 * never offered.
 *
 * Two teeth, and each closes a door the other cannot. The SHAPE test in `selectedMonth()` is the
 * read-side floor every path passes through, including the scheduled delivery; it stays a shape test
 * rather than a membership one because `WithholdingTaxReturn` legitimately carries `2026-Q1` there
 * and `createFromFormat('Y-m-d', '2026-Q1-01')` throws. `updatedPeriod()` is the membership test for
 * the update door — the one that catches a WELL-FORMED month from a year the page is not showing,
 * which no shape test can.
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

/**
 * Reach the trait's protected period methods the way the pages do.
 *
 * Named for this file — `ledgerPeriod` is already declared by `MonthlyLedgerStatementsTest`, and two
 * file-scope functions of one name is a fatal redeclaration that exits the whole suite with no
 * output at all (see `TestHelperUniquenessConformanceTest`).
 */
function ledgerPeriodOf(object $page, string $method): mixed
{
    $reflected = new ReflectionMethod($page::class, $method);
    $reflected->setAccessible(true);

    return $reflected->invoke($page);
}

it('reads a month that does not exist as no month at all', function () {
    foreach (['-13', '-99', '-00'] as $suffix) {
        $period = $this->year.$suffix;

        // The upstream behaviour this guard exists for, pinned rather than described: Carbon does
        // not refuse a thirteenth month, it lands in another one. A release that started throwing
        // would turn this red instead of quietly changing what a malformed period does.
        $overflowed = Carbon::createFromFormat('Y-m-d', $period.'-01');
        expect($overflowed)->not->toBeFalse()
            ->and($overflowed->format('Y-m'))->not->toBe($period);

        $page = new TrialBalance;
        $page->year = $this->year;
        $page->period = $period;

        expect(ledgerPeriodOf($page, 'periodStart')->toDateString())->toBe($this->year.'-01-01')
            ->and(ledgerPeriodOf($page, 'periodEnd')->toDateString())->toBe($this->year.'-12-31')
            ->and(ledgerPeriodOf($page, 'periodLabel'))->toBe((string) $this->year);
    }
});

it('still narrows to a month that does exist', function () {
    // The control. A floor that refused every period would satisfy the case above and take the
    // monthly close — the thing these pages were built for — away with it.
    $page = new TrialBalance;
    $page->year = $this->year;
    $page->period = $this->year.'-03';

    expect(ledgerPeriodOf($page, 'periodStart')->toDateString())->toBe($this->year.'-03-01')
        ->and(ledgerPeriodOf($page, 'periodEnd')->toDateString())->toBe($this->year.'-03-31');
});

it('holds a period arriving from a later update to the test mount already applies', function () {
    Livewire::test(TrialBalance::class)
        ->assertSet('year', $this->year)
        // Malformed.
        ->set('period', $this->year.'-13')
        ->assertSet('period', null)
        // Well formed, and from a fiscal year this page is not showing — the pickers-disagree state
        // no shape test can catch.
        ->set('period', ($this->year - 7).'-03')
        ->assertSet('period', null)
        // The control: a month its own picker offers survives untouched.
        ->set('period', $this->year.'-03')
        ->assertSet('period', $this->year.'-03');
});

it('keeps the quarter the withholding return offers', function () {
    // The other control, and the one that matters most: this page's period is a QUARTER, and a month
    // regex in `updatedPeriod()` would discard every Form 41 period — which is SW-131 again, through
    // the door SW-224 opened.
    Livewire::test(WithholdingTaxReturn::class)
        ->set('period', $this->year.'-Q2')
        ->assertSet('period', $this->year.'-Q2');
});
