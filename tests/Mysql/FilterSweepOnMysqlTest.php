<?php

/**
 * Every filter in the panel, run against the REAL driver.
 *
 * The default suite sweeps all of them on sqlite `:memory:`, and green there is a statement about
 * sqlite. That is usually enough — a filter's SQL is driver-agnostic — but the class of defect
 * this sweep exists to catch is precisely the one where it is not:
 *
 *  - `order by floors.floor` was a MySQL **1054** in production. Sqlite raises its own error for
 *    the same statement, so that one WOULD have been caught — once the sweep actually applied a
 *    relationship filter, which for its whole life it did not.
 *  - `select tbl.*, x, *` is accepted by sqlite and is a **syntax error** on MySQL. That shape
 *    already reached production once (`FixedAssetResource`, 2026-08-17) and 500'd the fixed-asset
 *    list, the register CSV and every global search while 5,180 tests passed. A filter that adds a
 *    select, a `having` over an alias, or a `groupBy` can build it again.
 *  - Sort and grouping collations differ, and `ONLY_FULL_GROUP_BY` is on by default on MySQL 8 and
 *    absent from sqlite entirely.
 *
 * So the same sweep is run twice, on two drivers, and only this run can say "this works on the
 * database the operator uses".
 *
 * **Read-only, and NO `RefreshDatabase`** — the tier's rule (see README). It reads the baseline
 * `composer qa:baseline` builds, which is a populated database rather than a migrated empty one;
 * that is also what makes the coverage floor below mean something, since a filter that returns
 * nothing exercises very little.
 */

use App\Models\Asset;
use App\Models\TenantUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FilterSweep;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The MySQL tier needs a MySQL connection — see tests/Mysql/README.md.');
    }

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('runs every filter on every admin table against MySQL', function () {
    // The baseline's own super_admin, not a fabricated one: this tier writes nothing.
    $admin = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first();

    expect($admin)->not->toBeNull('The QA baseline has no super_admin — rebuild it with `composer qa:baseline`.');

    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();

    $this->actingAs($admin);

    $report = FilterSweep::report();

    $pages = [
        ...FilterSweep::adminPages(),
        ...FilterSweep::tablePages('Filament/Admin/Pages', 'App\\Filament\\Admin\\Pages\\'),
        ...FilterSweep::tablePages('Filament/Admin/Widgets', 'App\\Filament\\Admin\\Widgets\\'),
    ];

    asTenant($asset, function () use (&$report, $pages) {
        foreach ($pages as $page) {
            try {
                // A page whose own mount fails is a different bug from a filter that fails, and
                // saying which is the point — but it still has to be reported, not skipped.
                Livewire::test($page);
            } catch (Throwable $e) {
                $report['failures'][] = $page.' (mount) → '.$e::class.': '.$e->getMessage();

                continue;
            }

            FilterSweep::sweepPage($page, $report);
        }
    });

    expect($report['failures'])->toBe([], "MySQL filter failures:\n".implode("\n", $report['failures']));

    // Assert the sweep found work to do. Reporting a clean run over a set it never populated is
    // exactly what let `order by floors.floor` live on eleven filters for a year, so the floors
    // are the same ones the sqlite sweep asserts, summed across its four shards.
    expect($report['passed'])->toBeGreaterThan(200, 'The MySQL sweep applied almost nothing — is the baseline populated?');
    expect($report['matched'])->toBeGreaterThan(60, 'No filter returned a row — the sweep proves nothing about real data.');
});

it('runs every relation-manager filter against MySQL', function () {
    // Relation managers hold about one filter in eight and are reachable from no list page, so
    // they are the easiest place for one to rot: you only meet it by opening a specific record.
    $admin = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail();
    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();

    $this->actingAs($admin);

    $report = FilterSweep::report();
    $managers = 0;

    asTenant($asset, function () use (&$report, &$managers) {
        foreach (FilterSweep::adminPages() as $page) {
            $resource = $page::getResource();

            // A real owner record of the right model. Resources the baseline leaves empty are
            // skipped rather than invented — this tier writes nothing.
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
                    $probe = Livewire::test($managerClass, ['ownerRecord' => $owner, 'pageClass' => $page]);
                    $filters = $probe->instance()->getTable()->getFilters();
                    FilterSweep::probeFiltersForm($probe->instance());
                } catch (Throwable $e) {
                    $report['failures'][] = $managerClass.' (mount) → '.$e::class.': '.$e->getMessage();

                    continue;
                }

                foreach ($filters as $name => $filter) {
                    try {
                        $values = FilterSweep::valuesFor($filter);
                    } catch (Throwable $e) {
                        $report['failures'][] = $managerClass.'::'.$name.' (options) → '.$e::class.': '.$e->getMessage();

                        continue;
                    }

                    foreach ($values as $value) {
                        try {
                            $component = Livewire::test($managerClass, ['ownerRecord' => $owner, 'pageClass' => $page])
                                ->filterTable($name, $value);

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

    expect($report['failures'])->toBe([], "MySQL relation-manager filter failures:\n".implode("\n", $report['failures']));
    expect($managers)->toBeGreaterThan(8);
    expect($report['passed'])->toBeGreaterThan(20);
});

it('runs every portal filter against MySQL', function () {
    // The portal is the same tables through a different renderer and a different guard, so a
    // driver-level defect there is invisible to the admin sweep above.
    $tenantUser = TenantUser::query()->first();

    expect($tenantUser)->not->toBeNull('The QA baseline has no portal user — rebuild it with `composer qa:baseline`.');

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs($tenantUser, 'portal');

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

    expect($report['failures'])->toBe([], "MySQL portal filter failures:\n".implode("\n", $report['failures']));
    expect($report['passed'])->toBeGreaterThan(10);
});
