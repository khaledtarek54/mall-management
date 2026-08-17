<?php

namespace Tests\Support;

use App\Models\Asset;
use Database\Seeders\DemoSeeder;
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
    public static function countRecords($records): int
    {
        return method_exists($records, 'total') ? (int) $records->total() : $records->count();
    }

    /** Apply every filter on one list page, one value at a time, and force the query to run. */
    public static function sweepPage(string $pageClass, array &$report): void
    {
        $probe = Livewire::test($pageClass);
        $filters = $probe->instance()->getTable()->getFilters();

        foreach ($filters as $name => $filter) {
            foreach (self::valuesFor($filter) as $value) {
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
}
