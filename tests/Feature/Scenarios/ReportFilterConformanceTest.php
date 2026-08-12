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

it('scopes the property filter to what the operator may see', function () {
    // A filter listing every property in the portfolio tells a user which malls exist even when
    // choosing one returns nothing — a leak whether or not the data follows.
    // Assert on the component's behaviour, not on the source text — the docblock legitimately
    // NAMES `Asset::pluck` as the thing not to do, and a source scan cannot tell the warning from
    // the offence.
    $options = ReportFilters::property(fn () => null)->getOptions();

    expect($options)->toBe(TenantScope::selectableAssetOptions());
});

it('translates every shared filter label in English and Arabic', function () {
    $missing = [];

    foreach (['as_of', 'from', 'to', 'property', 'all_visible_properties'] as $key) {
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
