<?php

use App\Support\ActivityLogging;
use App\Support\ActivityVocabulary;

/**
 * Every audited `*_id` either names its record or says why it cannot.
 *
 * `ActivityVocabulary::FOREIGN_KEYS` turns an id into a record name, so a diff reads "Lease L-0042"
 * rather than "Lease 328". An UNREGISTERED id is not an error — it silently renders the number,
 * which reads as data and is useless to the person reading the trail.
 *
 * That was tolerable while 598 columns were audited and the ids in them were hand-picked. The flip
 * takes it to 1,034 and pulls in 42 `*_id` columns nobody had considered, so the question needs a
 * gate rather than a habit.
 *
 * It is a decision, not a lookup: "ends in `_id`" is wrong here three different ways — `national_id`
 * and `tax_id` are NUMBERS on a person or a company, `gateway_transaction_id` is a provider's own
 * string, and `noteable_id`/`source_id`/`declared_by_id` are morph halves whose `*_type` is
 * excluded as structural, so there is nothing to resolve them against. Registering any of those
 * blindly would render whichever row happened to share the number.
 */
it('accounts for every audited *_id column', function () {
    $registered = array_keys((new ReflectionClass(ActivityVocabulary::class))->getConstant('FOREIGN_KEYS'));
    $unaccounted = [];
    $checked = 0;

    foreach (array_keys(ActivityLogging::COVERAGE_FLOOR) as $model) {
        $instance = new ('App\\Models\\'.$model);

        foreach ($instance->attributesToBeLogged() as $column) {
            if (! str_ends_with($column, '_id')) {
                continue;
            }

            $checked++;

            if (in_array($column, $registered, true) || array_key_exists($column, ActivityVocabulary::NOT_A_REFERENCE)) {
                continue;
            }

            $unaccounted[$column] = $model;
        }
    }

    // A sweep that found no id columns agrees with any register at all.
    expect($checked)->toBeGreaterThan(100, 'The sweep found almost no *_id columns — it is checking nothing.');

    expect($unaccounted)->toBe(
        [],
        'These audited columns render as a bare number in the activity log. Map them in '
            .'ActivityVocabulary::FOREIGN_KEYS, or record why they are not references in '
            .'NOT_A_REFERENCE: '.implode(', ', array_keys($unaccounted)),
    );
});

it('keeps NOT_A_REFERENCE honest', function () {
    // A stale entry reads as a decision somebody made about a column that no longer exists, and an
    // entry that is ALSO in FOREIGN_KEYS is two answers to one question.
    $registered = array_keys((new ReflectionClass(ActivityVocabulary::class))->getConstant('FOREIGN_KEYS'));

    $audited = [];
    foreach (array_keys(ActivityLogging::COVERAGE_FLOOR) as $model) {
        $audited = [...$audited, ...(new ('App\\Models\\'.$model))->attributesToBeLogged()];
    }
    $audited = array_unique($audited);

    foreach (ActivityVocabulary::NOT_A_REFERENCE as $column => $reason) {
        expect($audited)->toContain($column);
        expect($registered)->not->toContain($column);
        expect(str_word_count($reason))->toBeGreaterThan(8, "The reason recorded for {$column} is too thin to review.");
    }
});
