<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityLogging;
use App\Support\ActivityVocabulary;

/**
 * The audit flip may widen the trail; it may never narrow it.
 *
 * Inverting `->logOnly([...])` to "everything fillable minus a denylist" raises coverage almost
 * everywhere, and that is exactly what makes the opposite failure invisible: one denylist entry
 * naming a column some model was already auditing removes coverage while every headline number
 * says coverage went up. Not hypothetical — the first draft of {@see ActivityLogging::NEVER}
 * excluded `paid_amount` and `balance` as "derived", and Invoice, VendorBill and CreditNote audit
 * both today.
 *
 * So {@see ActivityLogging::COVERAGE_FLOOR} records what all 85 audited models logged before the
 * change, and every model's audited set must remain a superset of it — for the tranches already
 * flipped and, more importantly, for the ones still to come.
 */
it('never audits less than it did before the flip', function () {
    $lost = [];

    foreach (ActivityLogging::COVERAGE_FLOOR as $model => $floor) {
        $class = 'App\\Models\\'.$model;

        expect(class_exists($class))->toBeTrue("COVERAGE_FLOOR names {$model}, which no longer exists.");

        $audited = (new $class)->attributesToBeLogged();
        $missing = array_values(array_diff($floor, $audited));

        if ($missing !== []) {
            $lost[] = $model.': '.implode(', ', $missing);
        }
    }

    expect($lost)->toBe([], implode(' | ', $lost));
});

it('sweeps every audited model, so the floor cannot pass vacuously', function () {
    $models = glob(app_path('Models/*.php')) ?: [];
    $logging = array_values(array_filter(
        $models,
        fn (string $f): bool => str_contains((string) file_get_contents($f), 'LogsActivity'),
    ));

    expect(count(ActivityLogging::COVERAGE_FLOOR))->toBe(
        count($logging),
        'The floor no longer covers every audited model — a model added since the flip has no floor, '
            .'so nothing would notice it losing coverage.',
    );
});

it('labels every audited column on every model, in both languages', function () {
    // A column audited without a label humanises to an English word sitting in an Arabic cell —
    // the failure this whole vocabulary exists to prevent, and the one the flip could mass-produce.
    $vocabulary = app(ActivityVocabulary::class);
    $missing = [];

    foreach (array_keys(ActivityLogging::COVERAGE_FLOOR) as $model) {
        $class = 'App\\Models\\'.$model;
        $instance = new $class;
        $logName = $instance->getActivitylogOptions()->logName;

        foreach ($instance->attributesToBeLogged() as $column) {
            foreach (['en', 'ar'] as $locale) {
                if (! $vocabulary->hasFieldLabel($logName, $column, $locale)) {
                    $missing[$column][] = $locale;
                }
            }
        }
    }

    $report = array_map(fn ($v, $k) => $k.' ['.implode('+', array_unique($v)).']', $missing, array_keys($missing));

    expect($report)->toBe([], count($missing).' audited columns have no label: '.implode(', ', $report));
});

it('renders every audited column in real Arabic, not English wearing an ar locale', function () {
    // `hasFieldLabel(..., 'ar', fallback: false)` proves a key EXISTS in the Arabic file; it cannot
    // prove somebody put an English string in it. With 123 labels written in one pass that is the
    // realistic failure, and it is invisible on an English review — so this renders both locales
    // and compares. An Arabic label identical to its English one, or carrying no Arabic script at
    // all, is an untranslated string sitting on the operator's own panel.
    $vocabulary = app(ActivityVocabulary::class);
    $untranslated = [];
    $checked = 0;

    foreach (array_keys(ActivityLogging::COVERAGE_FLOOR) as $model) {
        $instance = new ('App\\Models\\'.$model);
        $logName = $instance->getActivitylogOptions()->logName;

        foreach ($instance->attributesToBeLogged() as $column) {
            app()->setLocale('en');
            $english = $vocabulary->field($logName, $column);

            app()->setLocale('ar');
            $arabic = $vocabulary->field($logName, $column);

            $checked++;

            if ($english === $arabic || preg_match('/\p{Arabic}/u', $arabic) !== 1) {
                $untranslated[$column] = $arabic;
            }
        }
    }

    app()->setLocale('en');

    // A sweep that rendered nothing agrees with everything.
    expect($checked)->toBeGreaterThan(500, 'The bilingual sweep rendered almost nothing — it is checking nothing.');

    expect($untranslated)->toBe([], count($untranslated).' audited columns render English on the Arabic panel: '
        .implode(', ', array_keys($untranslated)));
});

it('never audits a credential, and keeps the override that would matter if a floor ever held one', function () {
    // The plain version of this test could not fail. Deleting `password` from CREDENTIALS left it
    // green, because `password` is ALSO in NEVER and no model's floor contains it — so the ordinary
    // branch still excluded it and the override never ran. A test that cannot distinguish the thing
    // it names is not testing it.
    //
    // So assert the two properties that actually hold CREDENTIALS up, both reachable today:
    // it is a subset of NEVER (either path excludes), and no floor contains a credential (which is
    // the only condition under which the override would be load-bearing — and the reason it exists
    // is that the floor otherwise beats the denylist unconditionally).
    foreach (ActivityLogging::CREDENTIALS as $secret) {
        expect(ActivityLogging::NEVER)->toHaveKey($secret);
    }

    $inFloor = [];
    foreach (ActivityLogging::COVERAGE_FLOOR as $model => $floor) {
        foreach (array_intersect($floor, ActivityLogging::CREDENTIALS) as $secret) {
            $inFloor[] = "{$model}.{$secret}";
        }
    }

    expect($inFloor)->toBe([], 'A credential is in the coverage floor, so some model was auditing it: '.implode(', ', $inFloor));

    // And the end state, on the two models that actually expose the column.
    foreach ([User::class, Tenant::class] as $class) {
        expect((new $class)->getFillable())->toContain('password');
        expect((new $class)->attributesToBeLogged())->not->toContain('password');
    }
});
