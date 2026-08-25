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
 *
 * The ADMIN half lives in AdminFilterSweepShard{1..N}Test.php — it was a single
 * 80-second case, and Pest parallelises per file, so it set the floor on the whole
 * suite's wall time. The scaffolding is shared from Tests\Support\FilterSweep; the
 * first test below is what stops the split from losing coverage.
 */

use App\Models\Asset;
use App\Models\TenantUser;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\FilterSweep;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('sweeps every admin list page exactly once across the shards', function () {
    // The shards are the sweep now, so THIS is what guarantees the sweep still walks
    // everything: a page that falls out of the partition is a page nobody tests, and
    // nothing else would notice. Cheap — no seeding, pure class discovery.
    $all = FilterSweep::adminPages();

    $covered = [];
    for ($shard = 1; $shard <= FilterSweep::ADMIN_SHARDS; $shard++) {
        $covered = [...$covered, ...FilterSweep::adminPagesForShard($shard)];
    }
    sort($covered);

    expect($covered)->toBe($all, 'The admin shards do not partition the admin list pages.');
    expect($all)->toHaveCount(count(array_unique($all)), 'A page is swept twice.');

    // 41 admin tables at the time of writing; a sudden drop means discovery broke.
    expect(count($all))->toBeGreaterThanOrEqual(41);

    // A shard file per shard, or the pages in the missing shards are swept by nobody.
    expect(glob(base_path('tests/Feature/Resources/AdminFilterSweepShard*Test.php')))
        ->toHaveCount(FilterSweep::ADMIN_SHARDS, 'FilterSweep::ADMIN_SHARDS and the shard files on disk disagree.');
});

it('runs every filter on every relation-manager table without error', function () {
    // Relation managers hold ~1 filter in 8 and are NOT reachable from the list
    // pages, so the sweep above never touches them. They are the easiest place
    // for a filter to rot unnoticed: you only see one by opening a specific
    // record's edit page and scrolling.
    $this->seed(DemoSeeder::class);

    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $report = FilterSweep::report();
    $managers = 0;

    asTenant($asset, function () use (&$report, &$managers) {
        foreach (FilterSweep::adminPages() as $page) {
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
                    // Opening the panel is its own path — see FilterSweep::probeFiltersForm().
                    FilterSweep::probeFiltersForm($probe->instance());
                } catch (Throwable $e) {
                    $report['failures'][] = $managerClass.' (mount) → '.$e::class.': '.$e->getMessage();

                    continue;
                }

                foreach ($filters as $name => $filter) {
                    try {
                        // Same reason as the list sweep: opening a filter and deriving its real
                        // options are queries in their own right, so they are reported, not skipped.
                        $values = FilterSweep::valuesFor($filter);
                    } catch (Throwable $e) {
                        $report['failures'][] = $managerClass.'::'.$name.' (options) → '.$e::class.': '.$e->getMessage();

                        continue;
                    }

                    foreach ($values as $value) {
                        try {
                            $component = Livewire::test($managerClass, [
                                'ownerRecord' => $owner,
                                'pageClass' => $page,
                            ])->filterTable($name, $value);

                            $records = $component->instance()->getTableRecords();
                            $component->instance()->getTable()->getFilterIndicators();
                            $component->assertOk();

                            if (FilterSweep::countRecords($records) > 0) {
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

it('runs every filter on every table that lives on a PAGE rather than a resource', function () {
    // A report, a floor plan and the activity log are `Page`s with tables. `listPages()` discovers
    // `ListRecords` only, so their filters were swept by nobody — the activity log's are the ones
    // an auditor uses, and the occupancy map's is the only control on the page.
    $this->seed(DemoSeeder::class);

    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $report = FilterSweep::report();
    $swept = 0;

    $pages = [
        ...FilterSweep::tablePages('Filament/Admin/Pages', 'App\\Filament\\Admin\\Pages\\'),
        ...FilterSweep::tablePages('Filament/Admin/Widgets', 'App\\Filament\\Admin\\Widgets\\'),
    ];

    asTenant($asset, function () use (&$report, &$swept, $pages) {
        foreach ($pages as $page) {
            try {
                $filters = Livewire::test($page)->instance()->getTable()->getFilters();
            } catch (Throwable $e) {
                $report['failures'][] = $page.' (mount) → '.$e::class.': '.$e->getMessage();

                continue;
            }

            if ($filters === []) {
                continue;
            }

            $swept++;
            FilterSweep::sweepPage($page, $report);
        }
    });

    expect($report['failures'])->toBe([], "Page-table filter failures:\n".implode("\n", $report['failures']));

    // Assert the sweep found something to sweep — a discovery rule that silently matches nothing
    // reports a clean run over an empty set, which is this project's most repeated gate defect.
    expect($swept)->toBeGreaterThanOrEqual(2, 'No page-level table with filters was discovered.');
    expect($report['passed'])->toBeGreaterThan(3);
});

it('runs every filter on every portal table without error', function () {
    // The portal pages resolve resource URLs off the CURRENT panel; without
    // this they render against `admin` and blow up on a route that panel
    // doesn't own. Same setup the other portal tests use.
    Filament::setCurrentPanel(Filament::getPanel('portal'));

    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    $this->actingAs(makeTenantUser($lease->tenant), 'portal');

    $report = FilterSweep::report();

    try {
        $pages = [
            ...FilterSweep::listPages('Filament/Portal/Resources', 'App\\Filament\\Portal\\Resources\\'),
            ...FilterSweep::tablePages('Filament/Portal/Pages', 'App\\Filament\\Portal\\Pages\\'),
            ...FilterSweep::tablePages('Filament/Portal/Widgets', 'App\\Filament\\Portal\\Widgets\\'),
        ];

        foreach ($pages as $page) {
            FilterSweep::sweepPage($page, $report);
        }
    } finally {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    expect($report['failures'])->toBe([], "Portal filter failures:\n".implode("\n", $report['failures']));
    expect($report['passed'])->toBeGreaterThan(10);
})->skip(fn () => ! class_exists(TenantUser::class), 'portal not available');
