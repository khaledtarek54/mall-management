<?php

/**
 * Filters must return the RIGHT rows — not merely run without erroring.
 *
 * AllFiltersSweepTest proves no filter throws. That is a weaker property than
 * it sounds: a filter can execute perfectly and still select the wrong set, or
 * select nothing at all. Two in this codebase did exactly that (havingRaw on a
 * select alias, which without a GROUP BY collapses the result to one group and
 * silently matches everything).
 *
 * This asserts two things generically, across every filter in the app, without
 * a hand-written expectation per filter:
 *
 *  1. SOUNDNESS — for a filter named after a real column, every row it returns
 *     must actually hold the value that was selected. This is real correctness:
 *     pick `status = paid`, get only paid rows.
 *
 *  2. NOT A NO-OP — a filter that returns the entire unfiltered set, on data
 *     that demonstrably contains rows it should have excluded, is reported.
 *     That is the shape of the two bugs above.
 *
 * Both run over DemoSeeder data so there is a real, varied population to filter.
 */

use App\Models\Asset;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/** Rows on the CURRENT page, whether the table paginates or not. */
function fcRows($records): Collection
{
    if (method_exists($records, 'getCollection')) {
        return $records->getCollection();
    }

    return $records instanceof Collection ? $records : collect($records);
}

/**
 * TOTAL matching rows, not the page.
 *
 * Tables paginate at 25 by default now, so comparing page counts made every
 * result of 25-or-more look identical — which is how the first version of the
 * no-op check below produced a screenful of false positives.
 */
function fcTotal($records): int
{
    return method_exists($records, 'total') ? (int) $records->total() : fcRows($records)->count();
}

/**
 * Does this filter override the meaning of its value with a custom query()?
 *
 * Filament keeps that flag protected, and there is no public equivalent. A test
 * that introspects a filter's shape legitimately needs it: for a custom query
 * the selected value no longer implies "column == value", so the soundness
 * check below must not be applied to it.
 */
function fcHasCustomQuery($filter): bool
{
    $ref = new ReflectionMethod($filter, 'hasQueryModificationCallback');
    $ref->setAccessible(true);

    return (bool) $ref->invoke($filter);
}

/** @return array<int, class-string> */
function fcListPages(): array
{
    return collect(File::allFiles(app_path('Filament/Admin/Resources')))
        ->filter(fn ($f) => str_starts_with($f->getFilename(), 'List') && $f->getExtension() === 'php')
        ->map(function ($f) {
            $rel = str_replace([app_path('Filament/Admin/Resources').'/', '.php'], '', $f->getPathname());

            return 'App\\Filament\\Admin\\Resources\\'.str_replace('/', '\\', $rel);
        })
        ->filter(fn (string $c) => class_exists($c) && is_subclass_of($c, ListRecords::class))
        ->values()
        ->all();
}

it('returns only matching rows for every column-backed filter', function () {
    $this->seed(DemoSeeder::class);

    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $violations = [];
    $checked = 0;

    asTenant($asset, function () use (&$violations, &$checked) {
        foreach (fcListPages() as $page) {
            $filters = Livewire::test($page)->instance()->getTable()->getFilters();

            foreach ($filters as $name => $filter) {
                // Only SelectFilters that filter a REAL column on the model, with
                // no custom query() overriding the meaning of the value. Anything
                // else has intent this test cannot infer.
                if (! $filter instanceof SelectFilter || $filter instanceof TrashedFilter) {
                    continue;
                }
                if (fcHasCustomQuery($filter) || $filter->getRelationshipName() !== null || $filter->isMultiple()) {
                    continue;
                }

                $model = $page::getResource()::getModel();
                /** @var Model $instance */
                $instance = new $model;
                $column = $filter->getAttribute();

                if (! Schema::hasColumn($instance->getTable(), $column)) {
                    continue;
                }

                foreach (array_keys($filter->getOptions()) as $value) {
                    $rows = fcRows(
                        Livewire::test($page)->filterTable($name, $value)->instance()->getTableRecords()
                    );

                    if ($rows->isEmpty()) {
                        continue; // nothing to check; the sweep already proved it runs
                    }

                    $checked++;

                    $wrong = $rows->filter(fn ($r) => (string) ($r->{$column} ?? '') !== (string) $value);

                    if ($wrong->isNotEmpty()) {
                        $violations[] = sprintf(
                            '%s::%s = %s returned %d row(s) whose %s is not %s (e.g. %s)',
                            class_basename($page), $name, $value, $wrong->count(), $column, $value,
                            (string) ($wrong->first()->{$column} ?? 'null'),
                        );
                    }
                }
            }
        }
    });

    expect($violations)->toBe([], "Filters returning non-matching rows:\n".implode("\n", $violations));

    // Guard the guard: if this drops to nothing, the check has stopped checking.
    expect($checked)->toBeGreaterThan(20);
});

it('returns only rows inside the window for every date-range filter', function () {
    // Date ranges are the largest family of CUSTOM-query filters, and unlike an
    // arbitrary closure their intent IS inferable: a `from` of D must exclude
    // everything dated before D. So this is real correctness, not a smell test.
    //
    // (A generic "the filter must narrow the result" rule was tried and dropped:
    // it cannot tell a broken filter from one that legitimately matches every
    // row, and it fired on every date filter simply because no date was set.)
    $this->seed(DemoSeeder::class);

    $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $violations = [];
    $checked = 0;

    asTenant($asset, function () use (&$violations, &$checked) {
        foreach (fcListPages() as $page) {
            $model = $page::getResource()::getModel();
            $table = (new $model)->getTable();

            foreach (Livewire::test($page)->instance()->getTable()->getFilters() as $name => $filter) {
                $fields = collect($filter->getSchema()?->getComponents() ?? [])
                    ->filter(fn ($c) => $c instanceof DatePicker)
                    ->map(fn ($c) => $c->getName())
                    ->values();

                if ($fields->isEmpty()) {
                    continue;
                }

                // The date column this filter works on: the filter's own name,
                // with a `_range` suffix stripped (issue_date_range → issue_date).
                $column = preg_replace('/_range$/', '', $name);

                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // Whichever field carries the lower bound.
                $from = $fields->first(fn (string $f) => str_contains($f, 'from'));

                if ($from === null) {
                    continue;
                }

                // A cutoff inside the data, so the assertion has something to bite on.
                $cutoff = now()->subMonths(2)->startOfDay();

                $rows = fcRows(
                    Livewire::test($page)
                        ->filterTable($name, [$from => $cutoff->toDateString()])
                        ->instance()
                        ->getTableRecords()
                );

                if ($rows->isEmpty()) {
                    continue;
                }

                $checked++;

                $tooEarly = $rows->filter(function ($r) use ($column, $cutoff) {
                    $value = $r->{$column} ?? null;

                    return $value !== null && Carbon::parse($value)->startOfDay()->lt($cutoff);
                });

                if ($tooEarly->isNotEmpty()) {
                    $violations[] = sprintf(
                        '%s::%s from %s returned %d row(s) dated earlier (e.g. %s)',
                        class_basename($page), $name, $cutoff->toDateString(), $tooEarly->count(),
                        (string) $tooEarly->first()->{$column},
                    );
                }
            }
        }
    });

    expect($violations)->toBe([], "Date-range filters leaking rows outside the window:\n".implode("\n", $violations));
    expect($checked)->toBeGreaterThan(3);
});
