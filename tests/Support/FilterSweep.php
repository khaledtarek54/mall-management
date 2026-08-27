<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Support\AssignedAssets;
use App\Support\Filament\EntitySelectFilter;
use Database\Seeders\DemoSeeder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Throwable;

/**
 * Scaffolding for the filter sweeps — see AllFiltersSweepTest for what they prove.
 *
 * A class rather than file-scope functions because the admin sweep is SHARDED across
 * several test files (below), and Pest loads each test file into whichever parallel
 * worker owns it: helpers declared in one test file are simply absent in another, and
 * declaring them in each would be a fatal redeclaration the moment two shards landed
 * in the same worker.
 */
final class FilterSweep
{
    /** How many files the admin sweep is split across. Must match the shard files on disk. */
    public const ADMIN_SHARDS = 4;

    /**
     * How many files the RESTRICTED-operator sweep is split across.
     *
     * Fewer than the super_admin sweep because that operator can open fewer lists, and measured
     * rather than guessed: unsharded it ran 122s, which is above this suite's whole wall-clock — and
     * Pest parallelises per FILE, so it would have become the floor under every run on its own.
     */
    public const RESTRICTED_SHARDS = 2;

    /** Every ListRecords page in a panel, discovered from disk. */
    public static function listPages(string $panelDir, string $namespace): array
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

    /** Every admin list page, in a STABLE order so the shards partition deterministically. */
    public static function adminPages(): array
    {
        $pages = self::listPages('Filament/Admin/Resources', 'App\\Filament\\Admin\\Resources\\');
        sort($pages);

        return $pages;
    }

    /**
     * The slice of admin pages belonging to one shard.
     *
     * Round-robin, not contiguous blocks: adjacent pages tend to be alphabetically
     * related and similarly sized, so striding spreads the heavy tables evenly instead
     * of stacking them all into one shard.
     */
    public static function adminPagesForShard(int $shard, int $of = self::ADMIN_SHARDS): array
    {
        return array_values(array_filter(
            self::adminPages(),
            fn ($_, int $i): bool => $i % $of === $shard - 1,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Every Filament PAGE that renders a table — the tables the list sweep cannot see.
     *
     * A report or a floor plan is a `Page`, not a `ListRecords`, so `listPages()` walks straight
     * past it and its filters are swept by nobody. Two carry filters today (the activity log and
     * the occupancy map); discovery rather than a list is what covers the third.
     *
     * @return array<int, class-string>
     */
    public static function tablePages(string $panelDir, string $namespace): array
    {
        $pages = collect(File::allFiles(app_path($panelDir)))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->map(function ($f) use ($panelDir, $namespace) {
                $rel = str_replace([app_path($panelDir).'/', '.php'], '', $f->getPathname());

                return $namespace.str_replace('/', '\\', $rel);
            })
            ->filter(fn (string $c) => class_exists($c)
                && is_subclass_of($c, Page::class)
                && is_subclass_of($c, HasTable::class))
            ->values()
            ->all();

        sort($pages);

        return $pages;
    }

    /**
     * A plausible value for one filter, by type.
     *
     * Returns a LIST of values to try — a SelectFilter is only really exercised by
     * running each of its options, since each option can key a different query
     * branch (this is what would have caught a bad status string).
     */
    public static function valuesFor($filter): array
    {
        if ($filter instanceof TernaryFilter) {
            // Covers the true/false/blank branches, including TrashedFilter's
            // withTrashed / onlyTrashed / default.
            return [true, false, null];
        }

        if ($filter instanceof SelectFilter) {
            $options = array_keys($filter->getOptions());

            // `getOptions()` is EMPTY for a select that draws its options from anywhere but its
            // own array — a `->relationship()` filter, and every `EntitySelectFilter`, whose
            // options come from the `EntitySelect` built in `getFormField()`. So the sweep used
            // to run those on `[null]` alone: the blank branch, which returns before any of the
            // filter's SQL is assembled. Every relationship filter in the panel was walked and
            // none of them was ever actually applied.
            //
            // That is what hid a 1054 on twelve of them at once — `order by floors.floor` — for
            // as long as they have existed. A sweep that reports on a set it never populates is
            // this project's signature defect (CLAUDE.md: "when a gate counts, assert it counted
            // something"), so real keys are pulled from the source the filter itself would offer.
            if ($options === []) {
                $options = self::recordValuesFor($filter);
            }

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
     * Real keys for a select whose options are not in its own array.
     *
     * Deliberately NOT wrapped in a try/catch: assembling this query is itself part of what the
     * sweep is testing (`getRelationshipQuery()` is where the ordering column compiles), so a
     * throw here has to reach the caller's report rather than be swallowed into "no options".
     *
     * @return array<int, mixed>
     */
    public static function recordValuesFor(SelectFilter $filter): array
    {
        if ($filter->queriesRelationships()) {
            $query = $filter->getRelationshipQuery();

            return $query === null
                ? []
                : $query->limit(3)->pluck($query->getModel()->getQualifiedKeyName())->all();
        }

        if ($filter instanceof EntitySelectFilter && ($model = $filter->getEntityModel()) !== null) {
            return $model::query()->limit(3)->pluck((new $model)->getKeyName())->all();
        }

        return [];
    }

    /**
     * How many rows a table returned. getTableRecords() hands back a paginator
     * normally, but a plain Collection when the table has pagination turned off
     * (several relation managers do) — and Collection has no total().
     */
    public static function countRecords($records): int
    {
        return method_exists($records, 'total') ? (int) $records->total() : $records->count();
    }

    /** Apply every filter on one list page, one value at a time, and force the query to run. */
    public static function sweepPage(string $pageClass, array &$report): void
    {
        $probe = Livewire::test($pageClass);
        $filters = $probe->instance()->getTable()->getFilters();

        try {
            self::probeFiltersForm($probe->instance());
        } catch (Throwable $e) {
            $report['failures'][] = $pageClass.' (filter panel) → '.$e::class.': '.$e->getMessage();
        }

        foreach ($filters as $name => $filter) {
            try {
                $values = self::valuesFor($filter);
            } catch (Throwable $e) {
                $report['failures'][] = $pageClass.'::'.$name.' (options) → '.$e::class.': '.$e->getMessage();

                continue;
            }

            foreach ($values as $value) {
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

                    // The CHIP is a second query, and not the filter's own. Filament resolves the
                    // active filter's label by re-reading the record it names — a different
                    // builder, ordered differently — so a filter can return the right rows and
                    // still 500 the page that shows them. `index.blade.php` calls exactly this.
                    $component->instance()->getTable()->getFilterIndicators();

                    $component->assertOk();

                    if (self::countRecords($records) > 0) {
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

    /**
     * Open the filter panel, the way an operator does.
     *
     * Applying a filter and OPENING it are two different code paths, and only the first was ever
     * swept: `apply()` composes a where clause, while the panel builds the filters form and asks
     * each Select for its options — an `EntitySelect` browse closure, a relationship query, an
     * `->options()` callback. A picker that throws there renders as a dropdown that will not open,
     * which reads as "no such record" rather than as a bug.
     *
     * Read off the LIVEWIRE COMPONENT's own `getTableFiltersForm()`, never off
     * `$filter->getFormField()`. A field built outside a mounted container throws the moment
     * anything it evaluates reaches for `$container` — the trap CLAUDE.md records for
     * `getHelperText()` and `Repeater::getLabel()` — and two filters here do exactly that
     * (`ListUsers::roles`, `ListAccountingPeriods::fiscal_year_id`). Probing the detached field
     * would report those as broken while missing whatever the real panel does.
     */
    public static function probeFiltersForm(object $livewire): void
    {
        foreach ($livewire->getTableFiltersForm()->getFlatComponents(withHidden: true) as $component) {
            if (! $component instanceof Select) {
                continue;
            }

            $component->getOptions();

            if ($component->isSearchable()) {
                // Two characters: enough to reach the search branch, short enough that what is
                // being tested is the query compiling rather than what the fold matches.
                $component->getSearchResults('a');
            }
        }
    }

    /** A fresh, empty tally for one sweep. */
    public static function report(): array
    {
        return ['passed' => 0, 'matched' => 0, 'failures' => []];
    }

    /**
     * Sweep one shard's share of the admin tables.
     *
     * The whole sweep used to be a single 80-second test case. Pest's parallel runner
     * distributes work per FILE (paratest's per-method `--functional` mode crashes in
     * Pest's WrapperRunner), so one enormous case pinned one worker for 80s while the
     * other nine idled — it alone set the floor on how fast the suite could ever finish.
     * Split across files, the shards run concurrently.
     *
     * Nothing about the sweep itself is relaxed: every page still gets a fresh Livewire
     * component per filter value. The coverage assertions are the per-shard share of the
     * original totals, and AllFiltersSweepTest gates that the shards together still cover
     * every page exactly once.
     */
    public static function assertAdminShard(object $test, int $shard): void
    {
        // Seeded, not empty: an empty table would still catch bad SQL, but not a
        // formatter that trips over a real row, and every filter would trivially
        // "pass" by returning nothing.
        $test->seed(DemoSeeder::class);

        $asset = Asset::query()
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->firstOrFail();

        $test->actingAs(makeUser('super_admin', [$asset->id]));

        $report = self::report();
        $pages = self::adminPagesForShard($shard);

        asTenant($asset, function () use (&$report, $pages) {
            foreach ($pages as $page) {
                self::sweepPage($page, $report);
            }
        });

        expect($report['failures'])->toBe([], "Admin filter failures (shard {$shard}):\n".implode("\n", $report['failures']));

        // This shard's share of the original whole-sweep floors (>200 filter runs, >60
        // of them returning rows). Round-robin keeps the shards evenly sized.
        expect($report['passed'])->toBeGreaterThan(intdiv(200, self::ADMIN_SHARDS));
        expect($report['matched'])->toBeGreaterThan(intdiv(60, self::ADMIN_SHARDS));
    }

    /**
     * Sweep one shard's share of the admin tables as a PROPERTY-SCOPED operator.
     *
     * The four shards above run as `super_admin`, and `AssignedAssets::idsFor()` returns **null**
     * for a super admin — so every `->when($ids !== null, …)` scoping clause in the panel is SKIPPED
     * for the whole of that sweep. A filter that composes its own narrowing on top of a property
     * scope therefore compiles a shorter query there than it ever does in production.
     *
     * Same shape as the two gaps this suite has already been bitten by (green on sqlite saying
     * nothing about MySQL; a relationship filter swept on its blank branch alone): a sweep reporting
     * on a narrower set than the one it appears to cover.
     *
     * ONE extra operator rather than a role × filter matrix. What changes the SQL is whether the
     * operator is scoped at all, not which of the fourteen roles they hold — and a `manager` is
     * broad enough to open nearly every list while still being pinned to one mall.
     */
    public static function assertRestrictedShard(object $test, int $shard): void
    {
        $test->seed(DemoSeeder::class);

        $asset = Asset::query()
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->firstOrFail();

        $operator = makeUser('manager', [$asset->id]);
        $test->actingAs($operator);

        // The premise, asserted rather than assumed: if this ever returns null the whole file
        // silently becomes a slower copy of the super_admin sweep.
        expect(AssignedAssets::idsFor($operator))->toBe([$asset->id],
            'The operator is not property-scoped, so this sweep exercises the same branch as the shards.');

        $report = self::report();
        $pages = self::adminPagesForShard($shard, self::RESTRICTED_SHARDS);

        asTenant($asset, function () use (&$report, $pages) {
            foreach ($pages as $page) {
                // A list this role cannot open is not a filter failure — the role × screen matrix
                // reports those. Skipping keeps this file about the QUERY.
                if (! $page::getResource()::canAccess()) {
                    continue;
                }

                self::sweepPage($page, $report);
            }
        });

        expect($report['failures'])->toBe([], "Restricted-operator filter failures (shard {$shard}):\n".implode("\n", $report['failures']));

        expect($report['passed'])->toBeGreaterThan(intdiv(200, self::RESTRICTED_SHARDS));
        expect($report['matched'])->toBeGreaterThan(intdiv(60, self::RESTRICTED_SHARDS));
    }
}
