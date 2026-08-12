<?php

/*
|--------------------------------------------------------------------------
| A saved financial statement that saved nothing
|--------------------------------------------------------------------------
| `ReportParameters::parametersOf()` excluded EVERY trait property, because reflection reports a
| trait's property as declared on the class using it and Filament's `InteractsWithTable` would
| otherwise contribute `isTableLoaded`, `isTableReordering` and friends to every saved view.
|
| It also excluded `ScopesLedgerReport`, which declares `$year`, `$period` and `$assetId` — the
| ENTIRE parameter surface of the Income Statement, Balance Sheet, Cash Flow, Trial Balance, General
| Ledger and VAT Return.
|
| So those reports had no parameters at all. Saving a view of "Income Statement — September, Cairo
| Festival" stored an empty parameter set, and re-opening it — or worse, a scheduled email delivery
| of it — rendered whatever the DEFAULT period was. An owner receives a statement headed one month
| and filled with another's numbers, and nothing anywhere says so.
|
| The fix draws the line at OWNERSHIP rather than mechanism: a first-party trait under `App\` is our
| own code factored out, and its public typed scalars are as much a parameter as one written inline.
| A vendor trait is infrastructure the page did not choose.
*/

use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\RentRoll;
use App\Support\ReportParameters;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The admin panel is property-scoped, so `Page::getUrl()` needs a tenant to build a link at
    // all — without one it throws and `urlFor()`'s rescue() turns it into '#'. A real request
    // always has one; the test has to say so explicitly.
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('sees the parameters a first-party trait declares', function () {
    // The regression. `year`, `period` and `assetId` live on ScopesLedgerReport, not on the page
    // class, and were invisible while every trait property was excluded.
    //
    // Asserted as a SUPERSET rather than an exact list: `comparison` was added to the page itself
    // by RP-06, and pinning the exact array would turn every legitimate new parameter into a
    // failure here — which teaches whoever hits it to edit the assertion rather than read it.
    $parameters = array_keys(ReportParameters::parametersOf(IncomeStatement::class));

    foreach (['year', 'period', 'assetId'] as $fromTrait) {
        expect(in_array($fromTrait, $parameters, true))
            ->toBeTrue("{$fromTrait} is declared by ScopesLedgerReport and must still be a parameter");
    }

    // And the class's own properties are still seen — the fix must not have swapped one blind spot
    // for the other.
    expect(in_array('comparison', $parameters, true))->toBeTrue();
});

it('still keeps framework noise out of a saved view', function () {
    // The other half, and the reason the blanket exclusion existed. Filament's table traits
    // contribute a dozen public properties; a saved view carrying `isTableReordering` would restore
    // a UI state nobody saved on a report they only wanted the period of.
    $parameters = array_keys(ReportParameters::parametersOf(RentRoll::class));

    expect($parameters)->toContain('asOf')
        ->and($parameters)->not->toContain('isTableLoaded')
        ->and($parameters)->not->toContain('isTableReordering')
        ->and($parameters)->not->toContain('isTrackingDeselectedTableRecords');
});

it('builds a real link for a saved financial statement', function () {
    // It used to return '#'. A saved view whose URL is a dead anchor cannot be opened, linked or
    // delivered — the feature was inert for exactly the six reports an owner cares most about.
    $url = ReportParameters::urlFor(IncomeStatement::class, [
        'year' => 2026,
        'period' => '2026-09',
        'assetId' => 7,
    ]);

    expect($url)->not->toBe('#')
        ->and($url)->toContain('period=2026-09')
        ->and($url)->toContain('year=2026')
        ->and($url)->toContain('assetId=7');
});

it('drops a parameter the page does not declare', function () {
    // A saved view must not smuggle arbitrary query state onto a page. `bucket` belongs to ArAging.
    $url = ReportParameters::urlFor(BalanceSheet::class, ['period' => '2026-09', 'bucket' => 'd_90_plus']);

    expect($url)->toContain('period=2026-09')
        ->and($url)->not->toContain('bucket');
});

it('round-trips a saved view back onto the page it came from', function () {
    // End to end: what snapshot() captures is what apply() restores. A saved statement that reopens
    // on a different period is the whole bug, and asserting the parameter LIST alone would not
    // catch a snapshot that captured the names and dropped the values.
    $page = new IncomeStatement;
    $page->year = 2026;
    $page->period = '2026-09';
    $page->assetId = 7;

    $saved = ReportParameters::snapshot($page);

    expect($saved)->toBe(['year' => 2026, 'period' => '2026-09', 'assetId' => 7]);

    $reopened = new IncomeStatement;
    ReportParameters::apply($reopened, $saved);

    expect($reopened->period)->toBe('2026-09')
        ->and($reopened->year)->toBe(2026)
        ->and($reopened->assetId)->toBe(7);
});

it('covers every ledger report, not just the one that was noticed', function () {
    // The trait is shared, so the bug was shared. Fixing the Income Statement alone and calling it
    // done is the failure mode this codebase has hit before (the VendorBill GL omission).
    $ledgerReports = [
        \App\Filament\Admin\Pages\IncomeStatement::class,
        \App\Filament\Admin\Pages\BalanceSheet::class,
        \App\Filament\Admin\Pages\CashFlow::class,
        \App\Filament\Admin\Pages\TrialBalance::class,
        \App\Filament\Admin\Pages\GeneralLedger::class,
        \App\Filament\Admin\Pages\VatReturn::class,
    ];

    foreach ($ledgerReports as $page) {
        expect(in_array('period', array_keys(ReportParameters::parametersOf($page)), true))
            ->toBeTrue("{$page} lost its period parameter");
    }
});
