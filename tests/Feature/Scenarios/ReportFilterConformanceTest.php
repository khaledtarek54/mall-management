<?php

/*
|--------------------------------------------------------------------------
| The same question, asked the same way, on every report (RP-02)
|--------------------------------------------------------------------------
| "As of which date?" was asked by five reports, each declaring its own `DatePicker::make('asOf')`
| — structurally identical down to `->native(false)->live()` — under FOUR different translation keys
| for one concept: `reports.aged_as_of`, `rent_roll.as_of`, `expiration_schedule.as_of` and
| `sales_analytics.as_of`. An operator moving between the rent roll and the ageing report met the
| same control wearing a different name, and a sixth report would have invented a fifth key.
|
| Each was individually reasonable and they were collectively inconsistent, which is exactly the
| complaint RP-02 names.
|
| A one-off cleanup does not hold: the next report will hand-roll its own, because hand-rolling is
| what every existing example showed. So this gate refuses the raw component instead.
*/

use App\Support\ReportCatalogue;
use App\Support\ReportFilters;
use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\File;

/** The shared vocabulary, and the raw component each entry replaces. */
const SHARED_FILTERS = [
    'asOf' => 'ReportFilters::asOf()',
    'from' => 'ReportFilters::from()',
    'to' => 'ReportFilters::to()',
    'assetId' => 'ReportFilters::property()',
];

it('has no report hand-rolling a shared filter', function () {
    // REPORT PAGES only. `from`/`to` are also the names of ordinary table filters on a dozen
    // resources — a date range on the leases table is a different feature from a report's parameter
    // bar, and sweeping those in would make this gate a rename-everything demand rather than a
    // consistency rule. Scoping it here is what keeps the failure meaningful.
    $handRolled = [];

    foreach (File::allFiles(base_path('app/Filament/Admin/Pages')) as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $source = (string) file_get_contents($file->getPathname());

        if (array_key_exists($relative, ReportFilters::EXEMPT)) {
            continue;
        }

        foreach (SHARED_FILTERS as $name => $replacement) {
            foreach (['DatePicker', 'Select'] as $component) {
                if (str_contains($source, "{$component}::make('{$name}')")) {
                    $handRolled[] = "{$relative} declares {$component}::make('{$name}') — use {$replacement}";
                }
            }
        }
    }

    expect($handRolled)->toBe([], implode("\n", [
        'These report pages declare a filter the shared vocabulary already owns:',
        '  '.implode("\n  ", $handRolled),
        '',
        'Two reports asking the same question must not answer to different labels.',
        'Or register it in ReportFilters::EXEMPT with a reason it genuinely differs.',
    ]));
});

it('gives every exemption a real reason, and names a file that exists', function () {
    foreach (ReportFilters::EXEMPT as $path => $reason) {
        expect(file_exists(base_path($path)))->toBeTrue("{$path} no longer exists");
        expect(strlen($reason))->toBeGreaterThan(40, "{$path} needs a reason, not a label");
    }
});

it('gives the shared as-of filter one label everywhere', function () {
    // The regression in its plainest form. Four keys became one; if a report passes its own label
    // override the divergence is back, so the DEFAULT has to be the shared key.
    $filter = ReportFilters::asOf(fn () => null);

    expect($filter->getName())->toBe('asOf')
        ->and($filter->getLabel())->toBe(__('admin.reports.as_of'));
});

it('keeps every shared filter live, because a stale report is worse than a slow one', function () {
    // All four are `->live()`, and reports memoise their rows. A filter that updates the state
    // without re-rendering shows the OLD numbers under the NEW date — invisible, and it looks
    // authoritative. Asserting it here means a later "optimisation" that drops live() fails.
    foreach ([ReportFilters::asOf(fn () => null), ReportFilters::from(fn () => null), ReportFilters::to(fn () => null)] as $filter) {
        expect($filter->isLive())->toBeTrue("{$filter->getName()} must be live");
    }
});

it('pins the shared property filter to the mall the operator is standing in', function () {
    // This used to assert the picker's option list equalled `selectableAssetOptions()` — the
    // portfolio-listing leak was the concern, and a filter naming every mall tells a restricted
    // user which malls exist even when choosing one returns nothing.
    //
    // That concern did not go away; it moved somewhere stronger. The control is an `EntitySelect`
    // now, so `OptionDisplay` scopes both the options AND the label lookup that validates a
    // submitted value — the picker can no longer offer or ACCEPT a mall this operator may not see,
    // which the old option-list check could not say.
    //
    // What is asserted here instead is the thing that was actually wrong: with a real property
    // selected, the filter answers rather than asks. `TenantScope::reportAssetIds()` clamps every
    // pick back to that mall, so an editable control could only ever change the caption.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    $mall = makeAsset(['code' => 'HW']);
    Filament\Facades\Filament::setTenant($mall);

    try {
        $filter = ReportFilters::property(fn () => null);

        expect($filter->isDisabled())->toBeTrue('The shared property filter must be pinned when a mall is selected.');
        expect(TenantScope::reportAssetIds(null))->toBe([$mall->id]);
    } finally {
        Filament\Facades\Filament::setTenant(null, isQuiet: true);
    }
});

it('translates every shared filter label in English and Arabic', function () {
    $missing = [];

    foreach (['as_of', 'from', 'to', 'property', 'property_scope'] as $key) {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            if (__("admin.reports.{$key}") === "admin.reports.{$key}") {
                $missing[] = "{$key} [{$locale}]";
            }
        }
    }

    app()->setLocale('en');

    expect($missing)->toBe([], 'Untranslated filter labels: '.implode(', ', $missing));
})->group('i18n');

it('still sees the reports it is meant to be watching', function () {
    // The control. This gate is a source scan, so a Filament rename or a move out of app/Filament
    // would make it pass by finding nothing at all.
    $usingShared = 0;

    foreach (File::allFiles(base_path('app/Filament')) as $file) {
        if (str_contains((string) file_get_contents($file->getPathname()), 'ReportFilters::')) {
            $usingShared++;
        }
    }

    expect($usingShared)->toBeGreaterThanOrEqual(6)
        ->and(ReportCatalogue::REPORTS)->not->toBeEmpty();
});
