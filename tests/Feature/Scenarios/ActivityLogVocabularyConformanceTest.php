<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Support\ActivityLogChangeRenderer;
use App\Support\ActivityVocabulary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * **The gate on "the audit trail speaks the operator's language."**
 *
 * The rule it enforces is stated on `App\Support\ActivityVocabulary`: the activity log stores
 * DATA — a log name, an event, field keys, raw values — and every human-readable word is
 * resolved at READ time. That is what lets one stored row read correctly in Arabic and in
 * English, and what lets a wording fix reach rows written three years ago.
 *
 * A module can break that silently in several independent ways, so there is a sweep per way: a
 * new model whose log name has no label, an `activity('…')` call under a name no model declares,
 * a custom `->event('…')` nobody translated, a `logOnly()` column with no field label, a
 * `withProperties()` context key with no field label, a value vocabulary pointed at a group that
 * does not exist, and a `->log('…')` description stored as prose instead of a key.
 *
 * **Why this file exists at all.** `ActivityLogSubjectsAndAssetFormTest` was written in July
 * 2026 to catch exactly the first of those, and it caught nothing for a year: it filtered
 * models with `in_array(LogsActivity::class, class_uses_recursive($class))` against
 * `Spatie\Activitylog\Traits\LogsActivity`, **a class that does not exist in the version we
 * ship** (it is `Models\Concerns\LogsActivity`). `::class` is resolved by the compiler as a
 * plain string and never checks that the class exists, so the filter skipped all 64 models,
 * `$missing` was always `[]`, and the test was green while sweeping nothing. Test A below
 * therefore asserts the sweep FOUND something before asserting anything about what it found —
 * a conformance test that can pass vacuously is worse than no test, because it is also a claim.
 *
 * Detection is by `class_basename`, deliberately: it survives the upstream namespace move that
 * broke the original.
 *
 * **Every `Lang::has()` here passes `fallback: false`, and that is load-bearing.** The signature
 * is `has($key, $locale, $fallback = true)`, so `Lang::has('admin.fields.x', 'ar')` — the obvious
 * way to write an EN↔AR parity check — resolves through the fallback locale and answers TRUE for
 * Arabic whenever the key exists in English. A parity gate written that way can only detect a key
 * missing from BOTH catalogues, which is the one case nobody ships. Found by deleting a single
 * Arabic key and watching this file stay green.
 */

/**
 * Every model that logs activity, with its log name and logged columns.
 *
 * @return list<array{class: class-string, log_name: string, fields: list<string>}>
 */
function activityLoggingModels(): array
{
    $models = [];

    foreach (glob(app_path('Models').'/*.php') as $file) {
        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

        if (! class_exists($class)) {
            continue;
        }

        // By BASENAME, not by ::class — see the class docblock. The upstream trait moved from
        // Spatie\Activitylog\Traits to Spatie\Activitylog\Models\Concerns, and a hard-coded
        // FQCN turned this whole sweep into a no-op without turning anything red.
        $logsActivity = collect(class_uses_recursive($class))
            ->contains(fn (string $trait): bool => class_basename($trait) === 'LogsActivity');

        if (! $logsActivity) {
            continue;
        }

        $options = (new $class)->getActivitylogOptions();

        $models[] = [
            'class' => $class,
            'log_name' => $options->logName ?? Str::snake(class_basename($class)),
            'fields' => array_values(array_filter(
                $options->logAttributes,
                fn (string $f): bool => $f !== '*',
            )),
        ];
    }

    return $models;
}

/**
 * String literals passed to a given call under `app/` — `activity('x')`, `->event('x')`,
 * `->log('x')`.
 *
 * Comments are stripped before matching. Docblocks in this codebase quote real code freely
 * (ActivityVocabulary's own docblock contains the literal `->log('Invoice voided')` as the
 * example of what NOT to write), and a scanner that reads prose flags the documentation of a
 * rule as a violation of it.
 *
 * @return list<string>
 */
function activitySourceLiterals(string $pattern): array
{
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        if (preg_match_all($pattern, $code, $matches)) {
            $found = array_merge($found, $matches[1]);
        }
    }

    return array_values(array_unique($found));
}

/** Does this rendered string still look like an unresolved translation key? */
function activityKeyLeak(string $value): bool
{
    return preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){2,}$/i', $value) === 1;
}

it('sweeps a realistic number of activity-logging models (a vacuous sweep is not a pass)', function () {
    // The number is a floor, not a count — it exists so the filter silently matching nothing
    // is a red build. It was 0 for a year. See the class docblock.
    expect(count(activityLoggingModels()))->toBeGreaterThan(50);
});

it('has a subject label in en + ar for every model log name', function () {
    $missing = [];

    foreach (activityLoggingModels() as $model) {
        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has("admin.activity.subjects.{$model['log_name']}", $locale, fallback: false)) {
                $missing[] = "{$model['log_name']} [{$locale}] (from {$model['class']})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('has a subject label in en + ar for every hand-written activity() log name', function () {
    // Not every log name belongs to a model: the Settings and Property Overrides pages log
    // under `settings`, which no model declares — so the model sweep above could never see it
    // and the What badge rendered the literal `admin.activity.subjects.settings` in BOTH locales.
    $missing = [];

    foreach (activitySourceLiterals("/activity\('([a-z0-9_]+)'\)/") as $logName) {
        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has("admin.activity.subjects.{$logName}", $locale, fallback: false)) {
                $missing[] = "{$logName} [{$locale}]";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('has an event label in en + ar for every event raised anywhere', function () {
    // `voided` and `reversed` were both raised by services and translated in neither locale.
    $events = array_unique(array_merge(
        ['created', 'updated', 'deleted'],
        activitySourceLiterals("/->event\('([a-z0-9_]+)'\)/"),
    ));

    $missing = [];

    foreach ($events as $event) {
        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has("admin.activity.events.{$event}", $locale, fallback: false)) {
                $missing[] = "{$event} [{$locale}]";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('has a field label in en + ar for every column any model logs', function () {
    // `ActivityVocabulary::field()` always returns a string — it falls back to humanising the
    // column name — so "it rendered something" proves nothing. hasFieldLabel() asks the
    // question that matters: did it come from a catalogue, or did we just print the column?
    $vocabulary = app(ActivityVocabulary::class);
    $missing = [];

    foreach (activityLoggingModels() as $model) {
        foreach ($model['fields'] as $field) {
            foreach (['en', 'ar'] as $locale) {
                if (! $vocabulary->hasFieldLabel($model['log_name'], $field, $locale)) {
                    $missing[] = "{$model['log_name']}.{$field} [{$locale}]";
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

it('has a field label in en + ar for every context key the renderer surfaces', function () {
    // `reason`, `amount`, `bill`, `asset_id` come from withProperties() rather than a model diff,
    // so the logOnly() sweep above can never see them — and an unlabelled one humanises to an
    // English word sitting in the middle of an Arabic cell.
    $vocabulary = app(ActivityVocabulary::class);
    $missing = [];

    foreach (ActivityLogChangeRenderer::CONTEXT_KEYS as $key) {
        foreach (['en', 'ar'] as $locale) {
            if (! $vocabulary->hasFieldLabel(null, $key, $locale)) {
                $missing[] = "{$key} [{$locale}]";
            }
        }
    }

    expect(ActivityLogChangeRenderer::CONTEXT_KEYS)->not->toBe([])
        ->and($missing)->toBe([]);
});

it('resolves the vocabulary as one shared instance per request', function () {
    // Load-bearing, and silent if it regresses. `preloadReferences()` fills a cache on ONE
    // instance and every Changes cell reads it back through `app()`. With a transient binding
    // each cell gets a fresh empty vocabulary: the page still renders correctly, but the batch
    // is discarded and the foreign keys resolve one query at a time — the exact N+1 the preload
    // exists to prevent, invisible from the output.
    expect(app(ActivityVocabulary::class))->toBe(app(ActivityVocabulary::class));
});

it('resolves a whole page of foreign keys in one query per table', function () {
    // Counted, not asserted structurally, because every way this breaks leaves the page looking
    // perfect and only the query count different. It has broken twice already: once because the
    // vocabulary was not a singleton (each cell got an empty cache), and once because
    // `(array)` on a Collection yields its protected properties rather than its data, so the
    // batch collected nothing and every cell quietly queried for itself.
    $lease = makeLease(makeUnit(makeAsset()));

    $rows = collect(range(1, 20))->map(function () use ($lease) {
        $activity = new Activity;
        $activity->log_name = 'charge';
        $activity->subject_type = Charge::class;
        $activity->attribute_changes = ['old' => [], 'attributes' => ['lease_id' => $lease->id]];

        return $activity;
    });

    $vocabulary = app(ActivityVocabulary::class);
    $renderer = app(ActivityLogChangeRenderer::class);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $vocabulary->preloadReferences($rows);
    $rendered = $rows->map(fn (Activity $a): string => strip_tags($renderer->render($a)));

    // One whereIn for the whole page, however many rows reference the lease.
    expect($queries)->toBe(1)
        // ...and it really did resolve — a batch that silently returns nothing would also be 1.
        ->and($rendered->first())->toContain($lease->reference)
        ->and($rendered->first())->not->toContain((string) $lease->id);
});

it('points every foreign key at a real model that can name itself', function () {
    // The Changes column resolves `lease_id` to "LSE-AW-2026-0011" rather than "328". Two ways
    // that breaks silently: a class-string typo (the id renders raw again, exactly as before),
    // or a model with no label()/displayName() and none of the identifying columns — which is
    // why AccountingPeriod, EmployeeAdvance and MarketingBudget gained a label().
    $registry = (new ReflectionClass(ActivityVocabulary::class))->getConstant('FOREIGN_KEYS');
    $problems = [];

    foreach ($registry as $field => $class) {
        if (! class_exists($class)) {
            $problems[] = "{$field} => {$class} (no such class)";

            continue;
        }

        $model = new $class;
        $namesItself = collect(['label', 'displayName'])->contains(fn (string $m) => method_exists($model, $m))
            || collect(['reference', 'number', 'name', 'code', 'title'])
                ->contains(fn (string $c) => in_array($c, $model->getFillable(), true));

        if (! $namesItself) {
            $problems[] = "{$field} => {$class} (nothing to name it by — add a label())";
        }
    }

    expect($registry)->not->toBe([])
        ->and($problems)->toBe([]);
});

it('points every value vocabulary at a group that exists in en + ar', function () {
    // A typo'd prefix doesn't throw — the value just quietly renders as its raw token
    // (`draft`, `in_progress`) in the middle of an Arabic sentence.
    $registry = (new ReflectionClass(ActivityVocabulary::class))->getConstant('VALUE_VOCABULARY');
    $missing = [];

    foreach ($registry as $field => $prefix) {
        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has($prefix, $locale, fallback: false)) {
                $missing[] = "{$field} => {$prefix} [{$locale}]";
            }
        }
    }

    expect($registry)->not->toBe([])
        ->and($missing)->toBe([]);
});

it('stores every description as a translatable key, never as prose', function () {
    // A sentence written into the row at log time can never be translated afterwards — not by
    // this catalogue, not by anything. The five void/reverse services used to store English
    // ("Invoice voided"), which is precisely the shape this rejects.
    $missing = [];

    foreach (activitySourceLiterals("/->log\('([^']+)'\)/") as $description) {
        // A key: dot-separated snake_case, no spaces. Anything else is prose.
        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $description) !== 1) {
            $missing[] = "not a key: \"{$description}\"";

            continue;
        }

        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has("admin.activity.descriptions.{$description}", $locale, fallback: false)) {
                $missing[] = "{$description} [{$locale}]";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('renders a real payload with no raw key and no English left in Arabic', function () {
    // Render it, do not grep it: a label built at runtime is invisible to a source sweep, and
    // this is the assertion that speaks to what the operator actually sees.
    $invoice = new Activity;
    $invoice->log_name = 'invoice';
    $invoice->event = 'updated';
    $invoice->subject_type = Invoice::class;
    $invoice->attribute_changes = [
        'old' => ['status' => 'draft', 'total' => 45000, 'due_date' => '2026-09-01'],
        'attributes' => ['status' => 'issued', 'total' => 52000.5, 'due_date' => '2026-10-01'],
    ];

    $settings = new Activity;
    $settings->log_name = 'settings';
    $settings->description = 'settings.updated';
    $settings->properties = ['changes' => ['late_fee_percent' => ['from' => 2, 'to' => 2.5]]];

    app()->setLocale('ar');

    foreach ([$invoice, $settings] as $activity) {
        $rendered = strip_tags(renderActivityChanges($activity));

        foreach (preg_split('/\s+/', $rendered, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            expect(activityKeyLeak($token))->toBeFalse();
        }

        // Arabic script must actually be present — a cell of untranslated column names would
        // otherwise pass every assertion above it.
        expect(preg_match('/\p{Arabic}/u', $rendered))->toBe(1);
    }

    // The status token itself resolved, rather than leaving `draft`/`issued` mid-sentence.
    $arabic = strip_tags(renderActivityChanges($invoice));
    expect($arabic)->not->toContain('draft')
        ->and($arabic)->not->toContain('issued')
        // RTL reading: the arrow points from new back to old, or the diff reads backwards.
        ->and($arabic)->toContain('←');

    app()->setLocale('en');
    $english = strip_tags(renderActivityChanges($invoice));
    expect($english)->toContain('Draft')
        ->and($english)->toContain('Issued')
        ->and($english)->toContain('→');
});

it('makes the settings audit legible instead of a dash', function () {
    // The Settings and Property Overrides pages record from→to money figures under
    // `properties.changes`, a shape the renderer never read — so the one screen where a
    // late-fee percent can change showed "—" where its history should be.
    $settings = new Activity;
    $settings->log_name = 'settings';
    $settings->description = 'settings.updated';
    $settings->properties = ['changes' => ['late_fee_percent' => ['from' => 2, 'to' => 2.5]]];

    $rendered = strip_tags(renderActivityChanges($settings));

    expect($rendered)->not->toContain('—')
        ->and($rendered)->toContain('2.5');
});
