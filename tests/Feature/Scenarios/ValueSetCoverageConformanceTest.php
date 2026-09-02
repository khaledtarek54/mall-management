<?php

/*
|--------------------------------------------------------------------------
| A classification column is registered, or it is exempt with a reason
|--------------------------------------------------------------------------
| `ValueSets` was built from the 62 columns that were DB enums on 2026-08-12, and
| `NoDatabaseEnumsConformanceTest` asks only "is this column still an enum?" — never "is this new
| column registered?". So every classification column added in the ten weeks after that sweep was
| UNENFORCED, and nothing said so.
|
| It is not theoretical. In one day, four separate pieces of work each turned up a column with no
| set at all, every one found by accident while doing something else:
|
|   * `violations.category` — whose own migration promised the operator could extend it;
|   * `vendor_documents.type` — surfaced by a fixture writing a value no form offers;
|   * `employee_advances.paid_from` and `employee_advance_repayments.method` — found while tracing a
|     mirror ternary in a journalizer, both silently CLAMPED by their service, which is a wrong value
|     rather than a refusal;
|   * four more `cash|bank` columns behind the same clamps.
|
| And `facility_work_orders.status`, which the transition matrix branches on.
|
| ## Why the exemption half is not a loophole
|
| A third of the matches are not ours to constrain: a polymorphic `*_type` holds a morph alias and
| `MorphMap` already refuses an unmapped one — on READ as well as write, which this listener cannot
| do — while `charges.type` is validated against the charge-code CATALOGUE, which is a better answer
| than a list because the operator adds to it. Naming those with the registry that owns them is the
| honest reading. Leaving them out would make the gate unshippable, and shipping it with a silent
| skip for `*_type` would hide the next real one.
*/

use App\Support\ValueSets;
use Illuminate\Support\Facades\Schema;

/**
 * Column-name endings that mean "this column classifies the row".
 *
 * Deliberately a suffix list rather than "every string column": a `name`, a `description` or a
 * `reference` is free text and always will be, and a gate that demanded a set for those would be
 * exempted into meaninglessness on its first run.
 */
const CLASSIFICATION_SUFFIXES = [
    'status', 'type', 'method', 'category', 'kind', 'state', 'mode', 'basis', 'frequency',
    'priority', 'paid_from', 'direction', 'source', 'channel', 'stage', 'clock', 'nature',
    'treatment', 'role', 'unit', 'interval', 'currency', 'platform', 'level', 'format',
    // Added after a mutation audit found the list had drifted BEHIND the registry: ten columns
    // the team had judged classification-shaped enough to register in `ValueSets` were invisible
    // to this sweep, so a NEW column of any of those shapes would have shipped unenforced with
    // the gate silent. Adding them flagged zero new columns — the shapes were already classified,
    // which is exactly why nobody noticed the sweep could not see them.
    'section', 'timing', 'result', 'recipient', 'decision', 'pool',
    'normal_balance', 'funded_from', 'proceeds_account',
    // Which language a party reads (2026-08-28). Classification-shaped for the same reason
    // `currency` is: a small fixed set, and a value outside it does not error — `__()` falls
    // silently into the fallback language, so a typo'd `fr-CA` leaves the column looking set and
    // every document rendering in English.
    'locale',
    // What kind of money a bank account holds (2026-09-02). Classification-shaped for the same
    // reason `locale` is: a small fixed set whose wrong value does not error —
    // `BankAccount::defaultFor()` simply never matches it, so the account silently stops being
    // anybody's default while the register still shows it flagged, which reads as the defaulting
    // being broken rather than the row being wrong.
    'purpose',
];

/**
 * Registered columns whose NAME is not classification-shaped, and why that is right.
 *
 * The self-check below requires the suffix list to cover everything `ValueSets` registers. A column
 * may legitimately fail that and still belong in the registry — but it has to say so here rather
 * than weakening the list for everyone.
 */
const SHAPE_EXEMPT = [
    'document_templates.key' => 'A registry KEY, not a classification: the column names which document block a row is, and the set is `DocumentText::KEY_NAMES`. Widening the sweep to `key` pulls in the cache, settings and custom-field key columns, which are identifiers and always will be.',
];

/** @return array<int, string> `table.column` for every classification-shaped text column. */
function classificationColumns(): array
{
    $found = [];

    foreach (Schema::getTables() as $table) {
        $name = $table['name'];

        // Laravel's own plumbing. Not a judgement about classification — these tables are not ours.
        if (in_array($name, ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens'], true)) {
            continue;
        }

        foreach (Schema::getColumns($name) as $column) {
            if (! str_contains(strtolower((string) $column['type']), 'char')) {
                continue;
            }

            foreach (CLASSIFICATION_SUFFIXES as $suffix) {
                if ($column['name'] === $suffix || str_ends_with($column['name'], '_'.$suffix)) {
                    $found[] = $name.'.'.$column['name'];
                    break;
                }
            }
        }
    }

    sort($found);

    return $found;
}

it('registers or exempts every classification column', function () {
    $columns = classificationColumns();

    // The premise. A sweep that stopped finding columns would report a clean run — the failure mode
    // CLAUDE.md names three times over, most recently in a gate whose brace counter went negative.
    expect(count($columns))->toBeGreaterThan(80);

    $unclassified = [];

    foreach ($columns as $key) {
        [$table, $column] = explode('.', $key, 2);

        if (ValueSets::allowed($table, $column) !== null) {
            continue;
        }

        if (array_key_exists($key, ValueSets::UNCLASSIFIED)) {
            continue;
        }

        $unclassified[] = $key;
    }

    expect($unclassified)->toBe([], implode("\n", [
        'These columns classify a row and nothing constrains what they may hold. A typo, an import',
        'or a service that clamps instead of refusing will save a value no screen offers, and it will',
        'then match no filter and no report while looking correct on the record:',
        '  '.implode("\n  ", $unclassified),
        '',
        'Add the set to ValueSets::SETS — as `[Model::class, \'STATUSES\']` if the model already',
        'states it — or, if another registry genuinely owns the column, name it in',
        'ValueSets::UNCLASSIFIED with the reason.',
    ]));
});

it('has no stale exemption', function () {
    $columns = classificationColumns();
    $stale = [];

    foreach (ValueSets::UNCLASSIFIED as $key => $reason) {
        [$table, $column] = explode('.', $key, 2);

        if (! in_array($key, $columns, true)) {
            $stale[] = "{$key} — the column is gone, or no longer looks like a classification";

            continue;
        }

        if (ValueSets::allowed($table, $column) !== null) {
            $stale[] = "{$key} — now has a value set, so the exemption contradicts it";
        }

        // A reason has to survive review, and LENGTH is the wrong test for that — the first cut used
        // 60 characters and flagged "A morph alias. MorphMap owns it.", which is short and complete.
        // What a real reason does is NAME something: the registry that owns the column, or the fact
        // that makes it free text. So: either it names an identifier (a registry, a class, a method)
        // or it is long enough to be an argument. "Not needed" is neither, which is the point.
        $namesSomething = (bool) preg_match('/\b[A-Z][A-Za-z]+(?:\\\\[A-Z][A-Za-z]+)*(?:::\w+)?\b/', $reason);

        if (! $namesSomething && strlen($reason) < 80) {
            $stale[] = "{$key} — the reason names nothing and is too short to be an argument: \"{$reason}\"";
        }
    }

    expect($stale)->toBe([], implode("\n", $stale));
});

it('resolves every registered set to a non-empty list', function () {
    // A set declared as `[Model::class, 'STATUSES']` resolves through a constant, and a renamed or
    // deleted constant would silently expand to a two-element list — the class name and the constant
    // name — which is a set that refuses every real value. Worse than no entry.
    $broken = [];

    foreach (classificationColumns() as $key) {
        [$table, $column] = explode('.', $key, 2);
        $set = ValueSets::allowed($table, $column);

        if ($set === null) {
            continue;
        }

        if ($set === []) {
            $broken[] = "{$key} resolves to an EMPTY set — every value would be refused.";

            continue;
        }

        foreach ($set as $value) {
            if (str_contains($value, '\\')) {
                $broken[] = "{$key} contains `{$value}`, which is a class name — the constant it names has probably been renamed.";
            }
        }
    }

    expect($broken)->toBe([], implode("\n", $broken));
});

it('keeps the suffix list abreast of what ValueSets actually registers', function () {
    // The gate's coverage is bounded by a HAND-WRITTEN list, and nothing checked that list against
    // reality — so it drifted behind the registry and reported full coverage of a set it had
    // stopped collecting. This is the derive-don't-re-list rule applied to the gate itself: if the
    // team registers a column, its SHAPE must be one this sweep can see, or the next column of
    // that shape ships unenforced and silent.
    $unseen = [];

    foreach (array_keys(ValueSets::SETS) as $key) {
        if (! str_contains($key, '.')) {
            continue;
        }

        [, $column] = explode('.', $key, 2);

        if (array_key_exists($key, SHAPE_EXEMPT)) {
            continue;
        }

        $matched = false;

        foreach (CLASSIFICATION_SUFFIXES as $suffix) {
            if ($column === $suffix || str_ends_with($column, '_'.$suffix)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            $unseen[] = $key;
        }
    }

    expect($unseen)->toBe([], implode("\n  ", array_merge(
        ['These columns are registered in ValueSets but their shape is invisible to this sweep,',
            'so a NEW column of the same shape would ship unclassified with this gate silent:'],
        $unseen,
        ['Add the suffix to CLASSIFICATION_SUFFIXES, or add the column to SHAPE_EXEMPT with a reason.'],
    )));
});

it('keeps every shape exemption honest', function () {
    foreach (array_keys(SHAPE_EXEMPT) as $key) {
        // `toHaveKey($key, $msg)` reads its second argument as the expected VALUE, not a message —
        // the Pest trap this project has already recorded. Assert the boolean instead.
        expect(array_key_exists($key, ValueSets::SETS))
            ->toBeTrue("{$key} is shape-exempt but no longer registered.");
    }

    foreach (SHAPE_EXEMPT as $key => $reason) {
        expect(str_word_count($reason))->toBeGreaterThan(8, "{$key}'s exemption reason is too thin to review.");
    }
});
