<?php

/*
|--------------------------------------------------------------------------
| What a screen may OFFER is exactly what the column will ACCEPT
|--------------------------------------------------------------------------
| `ValueSets` answers the same question two ways, for two different callers:
|
|   * `allowed($table, $column)` — what a picker offers.
|   * `forTable($table)`         — what `ValueSets::guard()` accepts, from the global
|                                  `eloquent.saving: *` listener.
|
| While every set was a literal `const`, those could not disagree. The payment-rail catalogue made
| one of them dynamic, and the first cut widened only `allowed()` — so the deposit modal offered
| eight rails and the listener accepted two. Filament's `Rule::in` passed (the value WAS in the
| option list), the save threw a `DomainException`, and the operator saw a button do nothing.
|
| That is not a new failure. `DepositTransaction::methodOptions()` exists because it happened
| before, on 2026-08-18, and its docblock states the rule this gate now enforces mechanically:
| **"deriving it means a surface CANNOT offer a value the column refuses"**.
|
| Cheap, total, and it fails the moment the two derivations drift again.
*/

use App\Models\PaymentMethod;
use App\Support\ValueSets;

beforeEach(function () {
    // A rail that is NOT in any floor list. Without it the catalogue widens nothing, both
    // derivations return the same literal, and this gate passes for a reason unrelated to what it
    // claims — which is what it did on the first attempt, staying green under the exact mutation it
    // exists to catch.
    PaymentMethod::create([
        'code' => 'fawry',
        'name_en' => 'Fawry',
        'name_ar' => 'فوري',
        'for_inbound' => true,
        'for_outbound' => false,
    ]);
});

it('offers exactly what it accepts, for every column a catalogue widens', function () {
    // The premise, asserted: the catalogue really is widening something here.
    expect(ValueSets::allowed('payments', 'method'))->toContain('fawry');

    $drift = [];

    foreach (array_keys(ValueSets::SETS) as $key) {
        [$table, $column] = explode('.', $key, 2);

        $offered = ValueSets::allowed($table, $column) ?? [];
        $accepted = ValueSets::forTable($table)[$column] ?? [];

        sort($offered);
        sort($accepted);

        if ($offered !== $accepted) {
            $drift[] = sprintf(
                '%s — offered [%s] but accepts [%s]',
                $key,
                implode(', ', array_diff($offered, $accepted)) ?: '—',
                implode(', ', array_diff($accepted, $offered)) ?: '—',
            );
        }
    }

    expect($drift)->toBe([], implode("\n", [
        'These columns offer a different set from the one the saving listener accepts, so a screen',
        'can present a value that throws on save — a button that does nothing, with no explanation:',
        '  '.implode("\n  ", $drift),
    ]));
});

it('proves the sweep is looking at something', function () {
    // A gate over an empty set passes forever. This project has shipped one that swept zero models
    // and stayed green for a year.
    expect(count(ValueSets::SETS))->toBeGreaterThan(50)
        ->and(ValueSets::allowed('payments', 'method'))->toContain('fawry')
        ->and(ValueSets::forTable('payments')['method'] ?? [])->toContain('fawry');
});
