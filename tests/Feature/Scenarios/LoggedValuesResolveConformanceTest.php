<?php

use App\Support\ActivityVocabulary;
use App\Support\ValueSets;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/**
 * A logged column that stores a CODE must read as words — in both languages.
 *
 * The activity log stores data and resolves prose at read time; that is what lets one row read
 * correctly in Arabic and in English. A column with a `ValueSets` entry stores a code (`per_day`,
 * `internal`, `ppm`), so without a vocabulary it renders that code verbatim — an Arabic reader sees
 * `percent_of_value` in an otherwise Arabic diff, which is exactly what the design exists to stop.
 *
 * `ActivityLogVocabularyConformanceTest` already checks that every prefix REGISTERED here resolves.
 * This checks the other direction, which nothing did: that every logged code-valued column HAS one.
 * Six were missing when this was written — `facility_work_order.execution_type` / `work_order_type`,
 * `sla_penalty.basis` / `status`, `work_order_part.source` / `status` — all shipped, all rendering
 * raw.
 *
 * **And it checks coverage, not just existence.** `admin.facility.penalty.statuses` existed and was
 * missing `applied`, the status meaning *the vendor has been charged*. That gap was not confined to
 * the log: `FacilityWorkOrdersTable` renders the same group, so the work-order list showed the
 * operator a literal `admin.facility.penalty.statuses.applied`. A gate that only asked "is there a
 * vocabulary?" would have passed the moment the entry was added and left the hole.
 */

/** @return array<int, array{key: string, group: string|null, missing: list<string>}> */
function loggedCodeColumns(): array
{
    $vocabulary = (new ReflectionClass(ActivityVocabulary::class))->getConstant('VALUE_VOCABULARY');
    $rows = [];

    foreach (File::allFiles(app_path('Models')) as $file) {
        $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (! method_exists($class, 'getActivitylogOptions')) {
            continue;
        }

        $model = new $class;
        $options = $model->getActivitylogOptions();
        $logName = $options->logName ?? 'default';
        $table = $model->getTable();

        foreach ($options->logAttributes ?? [] as $column) {
            if (! is_string($column) || $column === '*' || str_contains($column, '.')) {
                continue;
            }

            // Only columns whose values are CODES. A free-text column has nothing to resolve.
            $set = ValueSets::allowed($table, $column);

            if ($set === null) {
                continue;
            }

            $key = $logName.'.'.$column;

            // The convention: `status` resolves through `admin.statuses.{log_name}` with no entry.
            $group = $vocabulary[$key]
                ?? ($column === 'status' && Lang::has("admin.statuses.{$logName}") ? "admin.statuses.{$logName}" : null);

            $missing = [];

            if ($group !== null) {
                foreach (['en', 'ar'] as $locale) {
                    foreach ($set as $value) {
                        // `fallback: false` — `Lang::has()` falls back to English, so the obvious
                        // check reports an Arabic gap as present.
                        if (! Lang::has("{$group}.{$value}", $locale, false)) {
                            $missing[] = "{$locale}:{$value}";
                        }
                    }
                }
            }

            $rows[] = ['key' => $key, 'group' => $group, 'missing' => $missing];
        }
    }

    return $rows;
}

it('discovers logged code-valued columns, so the sweep is not vacuously green', function () {
    // A sweep whose discovery silently matches nothing passes every assertion after it. This
    // codebase has shipped exactly that gate before — green for a year over zero models.
    $rows = loggedCodeColumns();

    expect(count($rows))->toBeGreaterThan(30)
        ->and(collect($rows)->pluck('key')->all())->toContain('invoice.status', 'sla_penalty.basis');
});

it('gives every logged code-valued column a vocabulary', function () {
    $orphans = collect(loggedCodeColumns())
        ->filter(fn (array $r) => $r['group'] === null)
        ->pluck('key')
        ->values()
        ->all();

    expect($orphans)->toBe([], 'These logged columns store a code with nothing to translate it, so '
        ."the audit trail prints the raw value:\n  ".implode("\n  ", $orphans)
        ."\nAdd each to ActivityVocabulary::VALUE_VOCABULARY, pointed at the group the column's own "
        .'FORM reads from.');
});

it('covers EVERY value in the set, in both languages', function () {
    // Existence is not coverage. A group that resolves four of five values reads perfectly until
    // somebody reaches the fifth state — and `applied` is reached by a service, not by a form, so
    // no amount of clicking around would have found it.
    $gaps = collect(loggedCodeColumns())
        ->filter(fn (array $r) => $r['missing'] !== [])
        ->map(fn (array $r) => $r['key'].' → '.$r['group'].' missing ['.implode(', ', $r['missing']).']')
        ->values()
        ->all();

    expect($gaps)->toBe([], "A value vocabulary does not cover its whole value set:\n  "
        .implode("\n  ", $gaps));
});
