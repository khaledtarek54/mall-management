<?php

/**
 * Every audited model needs a readable name on the activity log, in both languages.
 *
 * The activity log renders a row's subject through `admin.activity.subjects.<logName>`.
 * A model whose `useLogName()` has no matching key does not fall back to anything
 * sensible — Laravel returns the key itself, so the operator reads
 * "admin.activity.subjects.owner_statement_run" in the middle of the audit trail.
 *
 * That is exactly what shipped: nine models were missing (owner statements, runs and
 * disbursements, post-dated cheques, SLA policies and penalties, approval rules, vendor
 * documents and contract amendments) — every one of them from a module added after the
 * last time anyone looked at this list by hand. The E2E smoke caught ONE of the nine,
 * because it happened to be the one on screen for the seeded demo data.
 *
 * So it is a gate rather than nine keys: the list is derived from the models, and a new
 * audited model fails here until it is named.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/** Every log name declared by a model's getActivitylogOptions(). */
/**
 * Every log name in the app, RESOLVED rather than grepped.
 *
 * This used to regex `useLogName('x')` out of each model's source, which broke the moment the
 * shared audit policy (`App\Support\ActivityLogging::for()`) moved that call out of the models —
 * and would have broken again for any other refactor. Asking the model what its options actually
 * say is both shape-independent and strictly stronger: it is the value spatie will really use.
 *
 * @return list<string>
 */
function activityLogNames(): array
{
    $names = [];

    foreach (activityLoggingModelClasses() as $class) {
        $names[] = (new $class)->getActivitylogOptions()->logName;
    }

    $names = array_values(array_unique(array_filter($names)));
    sort($names);

    return $names;
}

/**
 * Every model class that logs activity. A model that cannot be instantiated is REPORTED, never
 * skipped — a sweep that quietly drops what it cannot read is the failure this file exists for.
 *
 * @return list<class-string<Model>>
 */
function activityLoggingModelClasses(): array
{
    $classes = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        if (! str_contains((string) file_get_contents($file), 'LogsActivity')) {
            continue;
        }

        $class = 'App\\Models\\'.basename($file, '.php');

        expect(class_exists($class))->toBeTrue("{$class} logs activity but could not be loaded.");

        $classes[] = $class;
    }

    return $classes;
}

it('names every audited model in English', function () {
    App::setLocale('en');

    $missing = array_diff(activityLogNames(), array_keys(__('admin.activity.subjects')));

    expect(array_values($missing))->toBe([], implode("\n", [
        'These models write to the activity log with no readable name, so the operator sees the',
        'raw translation key in the audit trail: '.implode(', ', $missing),
        'Add them to lang/en/admin.php under activity.subjects (and lang/ar/admin.php).',
    ]));
});

it('names every audited model in Arabic', function () {
    // Half this app's users read the Arabic UI. A key present in one language only means
    // the leak is invisible to whoever tested in the other.
    App::setLocale('ar');

    $missing = array_diff(activityLogNames(), array_keys(__('admin.activity.subjects')));

    expect(array_values($missing))->toBe([], 'Missing Arabic activity subject names: '.implode(', ', $missing));
});

it('finds some log names at all', function () {
    // The two tests above pass trivially if the scan returns nothing — a rename of
    // useLogName() or a move of the Models directory would silently disarm this gate.
    expect(count(activityLogNames()))->toBeGreaterThan(20);
});

it('every model that logs activity NAMES its log', function () {
    // The blind spot in this file. `activityLogNames()` used to enumerate models that CALL
    // useLogName(), so a model that logs and does not was invisible here — it files under spatie's
    // `default`, and the activity log rendered the raw key `admin.activity.subjects.default`.
    // Found by rendering the page, not by reading the models. Two models were doing it.
    //
    // Now resolved from the options themselves, so it also holds for a model that takes its
    // options from the shared policy rather than declaring them inline.
    $anonymous = [];

    foreach (activityLoggingModelClasses() as $class) {
        $logName = (new $class)->getActivitylogOptions()->logName;

        if (blank($logName) || $logName === 'default') {
            $anonymous[] = class_basename($class);
        }
    }

    expect($anonymous)->toBe([], 'These models write to the activity log without naming it, so their entries file under `default` and read as one undifferentiated bucket: '.implode(', ', $anonymous));
});
