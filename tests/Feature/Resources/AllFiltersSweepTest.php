<?php

/**
 * Exercise EVERY filter on EVERY table in both panels.
 *
 * Filters are the part of a Filament table most likely to fail only at
 * runtime and only for one person: the SQL is assembled from a closure, so a
 * wrong column name, a select alias used in a WHERE, or a relationship that
 * does not exist raises nothing until somebody ticks that one box. Two such
 * bugs already shipped in this pass (havingRaw on a select alias, silently
 * matching every row) and neither was visible from rendering the page.
 *
 * So this walks the filter list off the live table object — no hand-written
 * inventory to fall out of date — synthesises a realistic value per filter
 * type, applies it through the real Livewire request, and asserts the query
 * actually executes. Anything new is swept automatically.
 *
 * Coverage is asserted at the end so the sweep cannot quietly walk nothing.
 */

use App\Models\Asset;
use App\Models\TenantUser;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/** Every ListRecords page in a panel, discovered from disk. */
function sweepListPages(string $panelDir, string $namespace): array
{
    return collect(File::allFiles(app_path($panelDir)))
        ->filter(fn ($f) => str_starts_with($f->getFilename(), 'List') && $f->getExtension() === 'php')
        ->map(function ($f) use ($panelDir, $namespace) {
            $rel = str_replace([app_path($panelDir).'/', '.php'], '', $f->getPathname());

            return $namespace.str_replace('/', '\\', $rel);
        })
        ->filter(fn (string $c) => class_exists($c) && is_subclass_of($c, ListRecords::class))
        ->values()
        ->all();
}

/**
 * A plausible value for one filter, by type.
 *
 * Returns a LIST of values to try — a SelectFilter is only really exercised by
 * running each of its options, since each option can key a different query
 * branch (this is what would have caught a bad status string).
 */
function sweepValuesFor($filter): array
{
    if ($filter instanceof TernaryFilter) {
        // Covers the true/false/blank branches, including TrashedFilter's
        // withTrashed / onlyTrashed / default.
        return [true, false, null];
    }

    if ($filter instanceof SelectFilter) {
        $options = array_keys($filter->getOptions());

        // Relationship-backed selects can legitimately have no options on an
        // empty DB; still run the blank branch.
        return $options === [] ? [null] : [...array_slice($options, 0, 8), null];
    }

    // A custom Filter: if it has a form schema, synthesise one value per field
    // so the query branch behind it actually runs. If it has none, it is a
    // simple toggle.
    $components = $filter->getSchema()?->getComponents() ?? [];

    if ($components === []) {
        return [true];
    }

    $data = [];
    foreach ($components as $component) {
        $name = method_exists($component, 'getName') ? $component->getName() : null;
        if ($name === null) {
            continue;
        }

        $data[$name] = match (true) {
            $component instanceof DatePicker, $component instanceof DateTimePicker => now()->subYear()->toDateString(),
            $component instanceof Toggle => true,
            $component instanceof Select => array_key_first($component->getOptions() ?? []) ?? null,
            $component instanceof TextInput => '1',
            default => null,
        };
    }

    return [$data];
}

/**
 * How many rows a table returned. getTableRecords() hands back a paginator
 * normally, but a plain Collection when the table has pagination turned off
 * (several relation managers do) — and Collection has no total().
 */
function sweepCount($records): int
{
    return method_exists($records, 'total') ? (int) $records->total() : $records->count();
}

/** Apply every filter on one list page, one value at a time, and force the query to run. */
function sweepPage(string $pageClass, array &$report): void
{
    $probe = Livewire::test($pageClass);
    $filters = $probe->instance()->getTable()->getFilters();

    foreach ($filters as $name => $filter) {
        foreach (sweepValuesFor($filter) as $value) {
            $label = $pageClass.'::'.$name.' = '.json_encode($value);

            try {
                // A fresh component per value: filters persist in the session
                // now, so reusing one would stack them and stop testing the
                // filter in isolation.
                $component = Livewire::test($pageClass)->filterTable($name, $value);

                // filterTable only sets state. Reading the records is what
                // actually compiles and executes the SQL — and, on demo data,
                // renders each row through every column formatter, so a
                // null-unsafe formatStateUsing surfaces here too.
                $records = $component->instance()->getTableRecords();

                $component->assertOk();

                if (sweepCount($records) > 0) {
                    // Track that the sweep is running against POPULATED tables.
                    // Without this the whole thing could pass by filtering
                    // empty sets and prove nothing about real data.
                    $report['matched']++;
                }
            } catch (Throwable $e) {
                $report['failures'][] = $label.' → '.$e::class.': '.$e->getMessage();

                continue;
            }

            $report['passed']++;
        }
    }
}

it('runs every filter on every admin table without error, over real demo data', function () {
    // Seeded, not empty: an empty table would still catch bad SQL, but not a
    // formatter that trips over a real row, and every filter would trivially
    // "pass" by returning nothing. ~3.5s for 33 leases / 289 invoices and the
    // full operational spread behind them.
    $this->seed(DemoSeeder::class);

    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $report = ['passed' => 0, 'matched' => 0, 'failures' => []];

    asTenant($asset, function () use (&$report) {
        foreach (sweepListPages('Filament/Admin/Resources', 'App\\Filament\\Admin\\Resources\\') as $page) {
            sweepPage($page, $report);
        }
    });

    expect($report['failures'])->toBe([], "Admin filter failures:\n".implode("\n", $report['failures']));

    // 41 admin tables, most with several filters, each run across its branches.
    expect($report['passed'])->toBeGreaterThan(200);

    // …and a large share of those actually returned rows, so the sweep is
    // exercising real records rather than filtering empty tables.
    expect($report['matched'])->toBeGreaterThan(60);
});

it('runs every filter on every relation-manager table without error', function () {
    // Relation managers hold ~1 filter in 8 and are NOT reachable from the list
    // pages, so the sweep above never touches them. They are the easiest place
    // for a filter to rot unnoticed: you only see one by opening a specific
    // record's edit page and scrolling.
    $this->seed(DemoSeeder::class);

    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $report = ['passed' => 0, 'matched' => 0, 'failures' => []];
    $managers = 0;

    asTenant($asset, function () use (&$report, &$managers) {
        foreach (sweepListPages('Filament/Admin/Resources', 'App\\Filament\\Admin\\Resources\\') as $page) {
            $resource = $page::getResource();

            // An owner record of the right model — skip resources the demo
            // data leaves empty rather than inventing one.
            $owner = $resource::getEloquentQuery()->first();
            if ($owner === null) {
                continue;
            }

            foreach ($resource::getRelations() as $managerClass) {
                if (! is_string($managerClass) || ! class_exists($managerClass)) {
                    continue;
                }

                $managers++;

                try {
                    $probe = Livewire::test($managerClass, [
                        'ownerRecord' => $owner,
                        'pageClass' => $page,
                    ]);
                    $filters = $probe->instance()->getTable()->getFilters();
                } catch (Throwable $e) {
                    $report['failures'][] = $managerClass.' (mount) → '.$e::class.': '.$e->getMessage();

                    continue;
                }

                foreach ($filters as $name => $filter) {
                    foreach (sweepValuesFor($filter) as $value) {
                        try {
                            $component = Livewire::test($managerClass, [
                                'ownerRecord' => $owner,
                                'pageClass' => $page,
                            ])->filterTable($name, $value);

                            $records = $component->instance()->getTableRecords();
                            $component->assertOk();

                            if (sweepCount($records) > 0) {
                                $report['matched']++;
                            }
                        } catch (Throwable $e) {
                            $report['failures'][] = $managerClass.'::'.$name.' = '.json_encode($value)
                                .' → '.$e::class.': '.$e->getMessage();

                            continue;
                        }

                        $report['passed']++;
                    }
                }
            }
        }
    });

    expect($report['failures'])->toBe([], "Relation-manager filter failures:\n".implode("\n", $report['failures']));
    expect($managers)->toBeGreaterThan(8);
    expect($report['passed'])->toBeGreaterThan(20);
});

it('runs every filter on every portal table without error', function () {
    // The portal pages resolve resource URLs off the CURRENT panel; without
    // this they render against `admin` and blow up on a route that panel
    // doesn't own. Same setup the other portal tests use.
    Filament::setCurrentPanel(Filament::getPanel('portal'));

    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    $this->actingAs(makeTenantUser($lease->tenant), 'portal');

    $report = ['passed' => 0, 'matched' => 0, 'failures' => []];

    try {
        foreach (sweepListPages('Filament/Portal/Resources', 'App\\Filament\\Portal\\Resources\\') as $page) {
            sweepPage($page, $report);
        }
    } finally {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    expect($report['failures'])->toBe([], "Portal filter failures:\n".implode("\n", $report['failures']));
    expect($report['passed'])->toBeGreaterThan(10);
})->skip(fn () => ! class_exists(TenantUser::class), 'portal not available');
