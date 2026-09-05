<?php

use App\Models\Custody;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Support\AssistantFields;
use App\Support\SearchPolicy;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

/**
 * Every model the assistant can FIND must say whether it may be READ BACK.
 *
 * Being findable and being summarisable are different questions, and the gap between them is where
 * the damage would be: all 40 indexed models resolve a name typed into the chat, and quoting the
 * row of any of them would hand back whatever the table happens to carry — `password` is fillable
 * on `User` and `Tenant`, `metadata` holds operator-defined custom fields, and a payroll run
 * aggregates what individuals were paid.
 *
 * So a model is in `SUMMARISED` with its fields, or in `NOT_SUMMARISED` with a reason. A model that
 * is in neither fails the build, which means a NEW searchable model forces the decision rather than
 * defaulting either way.
 */
it('classifies every findable model as summarisable or refused', function () {
    $indexed = SearchPolicy::INDEXED;

    // The premise: a sweep reading an empty registry would report no offenders and pass.
    expect($indexed)->not->toBeEmpty();

    $classified = array_merge(
        array_keys(AssistantFields::SUMMARISED),
        array_keys(AssistantFields::NOT_SUMMARISED),
    );

    expect(array_values(array_map('class_basename', array_diff($indexed, $classified))))
        ->toBe([], 'Add each to AssistantFields::SUMMARISED with its fields, or NOT_SUMMARISED with a reason.');

    // A stale entry is a failure too: a reason describing a model that is no longer searchable
    // reads as a decision somebody made about today.
    expect(array_values(array_map('class_basename', array_diff($classified, $indexed))))
        ->toBe([], 'AssistantFields names a model that is no longer searchable.');
});

it('refuses personal data by name, with the reason visible', function () {
    // Not an accident of omission: these are findable so an HR user can navigate to them, and never
    // quoted into a chat panel a colleague may be looking over.
    foreach ([Employee::class, Payroll::class, User::class, Custody::class] as $model) {
        expect(AssistantFields::isSummarisable($model))->toBeFalse(class_basename($model).' must not be summarised');
        expect(AssistantFields::NOT_SUMMARISED[$model] ?? '')->not->toBe('');
    }
});

it('gives every refusal a reason somebody can review', function () {
    foreach (AssistantFields::NOT_SUMMARISED as $model => $reason) {
        // "Not needed" and "we thought about this" look identical from the outside, and only one of
        // them is a decision.
        expect(mb_strlen($reason))->toBeGreaterThan(40, class_basename($model).' needs a real reason');
    }
});

it('lists only columns that actually exist', function () {
    foreach (AssistantFields::SUMMARISED as $model => $spec) {
        $table = (new $model)->getTable();

        foreach ($spec['columns'] as $column) {
            // A misspelled column reads back as an absent value, which is indistinguishable from a
            // record that simply has none — so the field would silently never appear.
            expect(Schema::hasColumn($table, $column))
                ->toBeTrue("{$table}.{$column} does not exist");
        }

        foreach ($spec['derived'] ?? [] as $label => $method) {
            expect(method_exists($model, $method))
                ->toBeTrue(class_basename($model)."::{$method}() does not exist");
        }
    }
});

it('labels every summarised field in both languages', function () {
    $keys = [];

    foreach (AssistantFields::SUMMARISED as $spec) {
        $keys = array_merge($keys, $spec['columns'], array_keys($spec['derived'] ?? []));
    }

    foreach (array_unique($keys) as $key) {
        $label = "admin.fields.{$key}";

        // A field with no Arabic label renders in English on an Arabic panel — the silent half of
        // a bilingual system. `Lang::has()` falls back to English by default, so the check must
        // refuse the fallback or it passes for every key present in English only.
        if (Lang::has($label, 'en', false)) {
            expect(Lang::has($label, 'ar', false))
                ->toBeTrue("{$label} is labelled in English but not Arabic");
        }
    }
});
