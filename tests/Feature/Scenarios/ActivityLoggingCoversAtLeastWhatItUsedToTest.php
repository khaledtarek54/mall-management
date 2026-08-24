<?php

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

it('labels every column the flipped models now audit, in both languages', function () {
    // A column audited without a label humanises to an English word in an Arabic cell. Only the
    // models actually flipped so far are asserted; the rest still carry their own allowlists and
    // join this list as their tranche lands.
    $flipped = ['Lease', 'Invoice', 'Payment', 'CreditNote', 'VendorBill', 'Tenant', 'Vendor'];
    $vocabulary = app(ActivityVocabulary::class);
    $missing = [];

    foreach ($flipped as $model) {
        $class = 'App\\Models\\'.$model;
        $instance = new $class;
        $logName = $instance->getActivitylogOptions()->logName;

        foreach ($instance->attributesToBeLogged() as $column) {
            foreach (['en', 'ar'] as $locale) {
                if (! $vocabulary->hasFieldLabel($logName, $column, $locale)) {
                    $missing[] = "{$model}.{$column} [{$locale}]";
                }
            }
        }
    }

    expect($missing)->toBe([], 'Audited but unlabelled: '.implode(', ', $missing));
});

it('never audits a credential on any model, flipped or not', function () {
    // Asserted against the POLICY rather than the models, because an unflipped model excludes
    // these through its old allowlist and would pass with the denylist entry deleted.
    foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $secret) {
        expect(ActivityLogging::NEVER)->toHaveKey($secret);
    }

    foreach ([App\Models\User::class, App\Models\Tenant::class] as $class) {
        expect((new $class)->attributesToBeLogged())->not->toContain('password');
    }
});
