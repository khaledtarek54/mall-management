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

use Illuminate\Support\Facades\App;

/** Every log name declared by a model's getActivitylogOptions(). */
function activityLogNames(): array
{
    $names = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        if (preg_match('/useLogName\([\'"]([a-z_]+)[\'"]\)/', (string) file_get_contents($file), $m)) {
            $names[] = $m[1];
        }
    }

    sort($names);

    return array_unique($names);
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
