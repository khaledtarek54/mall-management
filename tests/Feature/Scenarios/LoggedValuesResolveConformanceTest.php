<?php

use App\Support\ActivityLogging;
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

        // Asked of `ActivityLogging`, not read off `$options->logAttributes`. Since the denylist
        // flip (2026-08-24) `for()` composes logFillable() + logOnly($alsoLog) − logExcept(), so
        // `logAttributes` holds only the few non-fillable columns three models pass through
        // $alsoLog — reading it literally left this sweep walking 85 models and finding ONE column.
        // The assertion below is what caught it; the fix is to ask the one resolver.
        foreach (ActivityLogging::auditedColumns($model) as $column) {
            // Only columns whose values are CODES. A free-text column has nothing to resolve.
            $set = ValueSets::allowed($table, $column);

            if ($set === null) {
                continue;
            }

            $key = $logName.'.'.$column;

            // A CATALOGUE column is labelled by its rows, not by a lang group — the operator adds
            // codes without a deploy, so no static list can ever cover it. `IsCodeCatalogue` reads
            // rows first (inactive included), then the same group the forms use, then the raw code,
            // and `CatalogueLabelsFollowARenameTest` gates that. Covered by BEING one.
            if (ActivityVocabulary::catalogueFor($logName, $column) !== null) {
                continue;
            }

            // Deliberately verbatim — an identifier, not a classification. Registered with a reason
            // rather than skipped on a column-name pattern, which would swallow the next one that
            // genuinely needed words.
            if (ActivityVocabulary::verbatimReason($column) !== null) {
                continue;
            }

            // The convention: `status` resolves through `admin.statuses.{log_name}` with no entry.
            $group = $vocabulary[$key]
                ?? ($column === 'status' && Lang::has("admin.statuses.{$logName}") ? "admin.statuses.{$logName}" : null);

            $missing = [];

            if ($group !== null) {
                foreach (['en', 'ar'] as $locale) {
                    foreach ($set as $value) {
                        // Asked of the RESOLVER, not re-derived here. "Which key labels this
                        // value" has exactly one definition — a value carrying a dot cannot be a
                        // leaf key, so `ActivityVocabulary` folds it — and a second copy in this
                        // gate is how a check comes to report on a rule the code no longer follows.
                        //
                        // `fallback: false` — `Lang::has()` falls back to English, so the obvious
                        // check reports an Arabic gap as present.
                        if (ActivityVocabulary::valueKey($group, (string) $value, $locale, fallback: false) === null) {
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

it('routes every catalogue-widened audited column through its catalogue', function () {
    // The registry is DERIVED, not invented: `ValueSets::catalogueWidenedColumns()` is the
    // independent source, so a catalogue that grows a column cannot quietly keep rendering it from
    // a static lang group — which would print `admin.enums.method.fawry` in the trail the first
    // time an operator added a rail.
    $audited = [];

    foreach (File::allFiles(app_path('Models')) as $file) {
        $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (! method_exists($class, 'getActivitylogOptions')) {
            continue;
        }

        $model = new $class;
        $audited[$model->getTable()] = $model->getActivitylogOptions()->logName ?? 'default';
    }

    $expected = [];

    foreach (ValueSets::catalogueWidenedColumns() as $tableColumn => [$catalogue]) {
        [$table, $column] = explode('.', $tableColumn, 2);

        // A catalogue-widened column on a model nobody audits has nothing to render in the trail.
        if (! isset($audited[$table])) {
            continue;
        }

        $expected[$audited[$table].'.'.$column] = $catalogue;
    }

    ksort($expected);
    $registered = ActivityVocabulary::catalogueValues();
    ksort($registered);

    expect($registered)->toBe($expected,
        'ActivityVocabulary::CATALOGUE_VALUES and ValueSets::catalogueWidenedColumns() disagree about which audited columns hold catalogue codes.');

    // The control: the derivation found something. An empty expectation would make the comparison
    // above pass over an empty registry.
    expect($expected)->not->toBeEmpty();
});
