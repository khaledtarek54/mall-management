<?php

use App\Filament\Admin\Pages\IncomeStatement;
use App\Models\ReportPreference;
use App\Services\Reports\ComparativeStatementService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The income statement names the mall it is reporting (SW-117)
|--------------------------------------------------------------------------
| `ScopesLedgerReport::hydrateLedgerScopeFromQuery()` ends with the property switcher having the
| LAST WORD: `$this->assetId = TenantScope::currentAssetId() ?? $this->assetId;`, under a comment
| saying why — left unpinned, the disabled picker names one mall while the rows underneath it come
| from another, "the single failure mode a financial statement must not have". That is the whole
| reason `PropertyField::reportScope()` exists.
|
| `IncomeStatement::mount()` then called `ReportPreferences::restore($this)` a SECOND time, AFTER
| that hydrate, so it could pick up `comparison` and `spread` — which the page parses from the
| query string after the hydrate has already run. `assetId` is a restored parameter too, so the
| standing preference beat the pin.
|
| Measured at HEAD 2026-09-04:
|   ReportParameters::parametersOf(IncomeStatement::class) = comparison, spread, year, period, assetId
|   ReportPreferences::VOLATILE                            = asOf, from, to, period, year, date
|   → remembered AND restored: comparison, spread, assetId
|
| So an operator who worked mall A yesterday, standing in mall B today, read a pinned, disabled
| scope picker saying "A" over figures `TenantScope::reportAssetIds()` had clamped back to B. Not a
| leak — the clamp holds, and the PDF header derives from the clamped set — but the caption and the
| figures disagree, which on a statement is the failure nobody re-checks.
|
| The fix parses this page's own two parameters BEFORE the shared hydrate, so the trait's single
| restore is the effective one and the pin stays last. The query string still wins, because
| `restore()` skips any key `request()->query` names.
*/
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->user = makeUser('super_admin');
    $this->actingAs($this->user);

    $this->elsewhere = makeAsset(['code' => 'ELSE']);
    $this->here = makeAsset(['code' => 'HERE']);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('reports the mall the operator is standing in, whatever they last chose', function () {
    ReportPreference::create([
        'user_id' => $this->user->id,
        'report' => IncomeStatement::class,
        'parameters' => ['assetId' => $this->elsewhere->id],
    ]);

    Filament::setTenant($this->here);

    $page = Livewire::test(IncomeStatement::class)->assertOk();

    // The picker is bound to this property and is rendered DISABLED with "pinned to the selected
    // property" beside it — so whatever it holds is what the operator reads as the scope.
    expect($page->instance()->assetId)->toBe($this->here->id)
        ->and($page->instance()->assetId)->not->toBe($this->elsewhere->id);
});

it('still gives this operator back the reading they last chose', function () {
    // The control. The pin must beat the memory for the PROPERTY and for nothing else — a fix that
    // simply stopped restoring would satisfy the test above and quietly delete RP-02.
    ReportPreference::create([
        'user_id' => $this->user->id,
        'report' => IncomeStatement::class,
        'parameters' => [
            'assetId' => $this->elsewhere->id,
            'comparison' => ComparativeStatementService::PRIOR_YEAR,
            'spread' => IncomeStatement::SPREAD_YTD,
        ],
    ]);

    Filament::setTenant($this->here);

    $page = Livewire::test(IncomeStatement::class)->assertOk();

    expect($page->instance()->comparison)->toBe(ComparativeStatementService::PRIOR_YEAR)
        ->and($page->instance()->spread)->toBe(IncomeStatement::SPREAD_YTD)
        ->and($page->instance()->assetId)->toBe($this->here->id);
});

it('leaves the preference restore to the shared scope bar, on every ledger report', function () {
    // The gate. The trait restores and THEN pins, in that order and in one place; a page that
    // restores again afterwards un-pins itself, which is exactly what this row was. Derived from
    // the trait's own users so a seventh statement is covered by using the bar.
    $pages = [];
    $offenders = [];

    foreach (glob(app_path('Filament/Admin/Pages/*.php')) as $path) {
        $source = sourceWithoutComments($path);

        if (! str_contains($source, 'use ScopesLedgerReport;')) {
            continue;
        }

        $pages[] = basename($path);

        if (str_contains($source, 'ReportPreferences::restore(')) {
            $offenders[] = basename($path);
        }
    }

    // The premise — six pages use the bar at HEAD 2026-09-04.
    expect(count($pages))->toBeGreaterThanOrEqual(6);

    expect($offenders)->toBe([], "A ledger report restores this operator's stored parameters itself.\n"
        ."`ScopesLedgerReport::hydrateLedgerScopeFromQuery()` already does that and then PINS the\n"
        ."property to the selected mall — a second restore after it puts yesterday's mall back on\n"
        ."the picker over today's figures. Parse the page's own parameters BEFORE calling the\n"
        ."hydrate instead:\n  ".implode("\n  ", $offenders));
});
