<?php

use App\Support\ChangeImpact;
use App\Support\ModalFieldReach;
use App\Support\MoneyDocumentDoors;

/**
 * **Two guards about the same failure, from opposite ends: a question one screen asks and another
 * does not, and an answer a screen takes and then throws away.**
 *
 * Both were shipped in the same week. `bank_account_id` reached six money forms and not the lease
 * page's deposit modal — the door an operator actually uses — and the first fix of THAT put the
 * field on the modal while the write still dropped it. Each half is invisible from the file it
 * lives in: the schema is correct, the `create()` is correct, and only the pair is wrong.
 */
it('makes every door onto a money document ask what its siblings ask', function () {
    $disagreements = MoneyDocumentDoors::disagreements();

    expect($disagreements)->toBe([], implode("\n", array_merge($disagreements, [
        '',
        'Add the field to that door, or — if it genuinely already knows the answer — register it in',
        'MoneyDocumentDoors::DOOR_DERIVES as `path.php::field` with the reason.',
    ])));
});

/**
 * A field the write does not carry renders, validates, and records NOTHING — worse than not
 * offering it, because the operator has been told the answer was taken.
 */
it('makes every action that builds a row inline keep what it collected', function () {
    $dropped = [];

    foreach (ModalFieldReach::scan() as $action) {
        foreach ($action['dropped'] as $field) {
            $key = "{$action['file']}::{$action['action']}::{$field}";

            if (array_key_exists($key, ModalFieldReach::COLLECTS_WITHOUT_PERSISTING)) {
                continue;
            }

            $dropped[] = "{$action['file']} [{$action['action']}] asks for `{$field}` "
                ."and never passes it to {$action['model']}::create()";
        }
    }

    expect($dropped)->toBe([], implode("\n", array_merge($dropped, [
        '',
        'Pass it through, or register it in ModalFieldReach::COLLECTS_WITHOUT_PERSISTING with the',
        'reason it drives the ACT rather than the RECORD.',
    ])));
});

/**
 * Both sweeps must be able to FIND something.
 *
 * This codebase has had gates go silently blind three times, and the tell was never the gate. The
 * `::` trap is the live one here: PHP returns `T_DOUBLE_COLON` as an ARRAY, so a `!== '::'` test
 * matches nothing — the first cut of `ModalFieldReach` scanned **zero** actions and reported a
 * clean sweep.
 */
it('is actually reading the panel', function () {
    $inline = ModalFieldReach::scan();

    expect($inline)->not->toBeEmpty('No inline-building action was found — the tokenizer is reading the wrong shape.');

    // Each examined action really did collect fields and really did build a row.
    foreach ($inline as $action) {
        expect($action['asks'])->not->toBeEmpty()
            ->and($action['writes'])->not->toBeEmpty();
    }

    // And the doors side can see the documents and their material columns at all.
    $documents = MoneyDocumentDoors::documents();

    expect($documents)->not->toBeEmpty();

    $withPolicy = array_filter(
        array_keys($documents),
        fn (string $model) => ($policy = ChangeImpact::POLICY[$model] ?? []) && ! empty($policy[ChangeImpact::DERIVED]),
    );

    expect($withPolicy)->not->toBeEmpty(
        'No money document has any DERIVED column, so the disagreement sweep is comparing empty sets.'
    );
});

/** An exemption for something that is no longer true is a claim nobody re-reads. */
it('keeps no stale derivation or non-persisted claim', function () {
    $doors = array_keys(MoneyDocumentDoors::doors());

    foreach (array_keys(MoneyDocumentDoors::DOOR_DERIVES) as $key) {
        [$path] = explode('::', $key, 2);

        // `in_array(...)` and not `toContain($path, $message)`: Pest matchers take no message
        // argument, so the sentence would be read as a SECOND expected value and the assertion
        // would fail on its own explanation. Recorded in CLAUDE.md; hit again writing this.
        expect(in_array($path, $doors, true))->toBeTrue("DOOR_DERIVES names {$path}, which is no longer a door.");
    }

    $actions = collect(ModalFieldReach::scan())
        ->map(fn (array $a) => "{$a['file']}::{$a['action']}")
        ->all();

    foreach (array_keys(ModalFieldReach::COLLECTS_WITHOUT_PERSISTING) as $key) {
        $parts = explode('::', $key);
        array_pop($parts);

        expect(in_array(implode('::', $parts), $actions, true))->toBeTrue(
            "COLLECTS_WITHOUT_PERSISTING names {$key}, which is no longer an inline-building action."
        );
    }
});
