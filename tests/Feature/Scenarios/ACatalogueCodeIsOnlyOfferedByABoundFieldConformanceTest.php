<?php

use App\Support\Filament\CatalogueAwareSelect;
use App\Support\ValueSets;

/**
 * A retired catalogue code stays savable only where a field the carve-out can REACH offers it.
 *
 * ## What the carve-out is
 *
 * `IsCodeCatalogue::catalogueOptions()` offers active rows only, and Filament derives a field's
 * `Rule::in` from the options it resolved — so retiring a code makes every record already carrying
 * it unsavable. `CatalogueAwareSelect` appends the stored value back, and it is installed as a
 * CONTAINER BINDING so that no call site has to remember it.
 *
 * ## What that binding does NOT cover, measured 2026-09-04
 *
 * `Field::make()` is `app(static::class, ['name' => $name])`. The binding names `Select`, so it is
 * reached by a plain `Select` and by nothing else:
 *
 *   - `Radio`, `ToggleButtons` and `CheckboxList` derive `Rule::in` from their own options in
 *     exactly the same way and resolve to their own classes. Converting a catalogue picker to one
 *     — a styling change, in review — would reinstate the 2026-08-18 defect in full, silently.
 *   - A SUBCLASS of `Select` resolves to itself too. `EntitySelect` is one; it picks records rather
 *     than codes today, which is why this has never bitten.
 *
 * Measured across `app/Filament` on 2026-09-04: 55 plain `Select::make()` calls on a governed
 * column and ZERO of anything else. This gate is here to keep that true, not to report a backlog —
 * so it fails on the FIRST one, and asserts its own sweep can see the shape it is looking for.
 *
 * The other half of SW-205 — an action modal on a parent record whose form writes a CHILD table, so
 * the record a `Select` resolves is not the row it writes — is recorded on `CatalogueAwareSelect`
 * itself. It is not gated here because no source read can tell which table a modal's `->action()`
 * writes, and because all five of those pickers are create-only, where offering a retired code
 * would be the opposite of what retiring one means.
 */
it('renders no catalogue column with a field class the container binding never sees', function () {
    $governed = array_keys(CatalogueAwareSelect::governedColumnNames());

    // The premise. A derivation that had gone empty would sweep for nothing and pass while
    // measuring nothing — the failure CLAUDE.md records under "a gate can report on a set it has
    // silently stopped collecting".
    expect(count($governed))->toBeGreaterThan(4);

    $offenders = [];
    $boundPickers = 0;

    foreach (filamentSources() as $file) {
        $body = (string) file_get_contents($file);
        $relative = str_replace(base_path().'/', '', $file);

        foreach ($governed as $column) {
            $quoted = preg_quote($column, '/');

            $boundPickers += (int) preg_match_all("/(?<![A-Za-z])Select::make\\(\\s*'{$quoted}'\\s*\\)/", $body);

            // `SelectFilter` is deliberately NOT caught: a filter reads, it never writes a record,
            // so it has no stored value to keep. The alternation has to end immediately before the
            // `::`, which is what tells the two apart.
            $pattern = "/(?<![A-Za-z])([A-Za-z]*(?:Radio|ToggleButtons|CheckboxList|Select))::make\\(\\s*'{$quoted}'\\s*\\)/";

            if (preg_match_all($pattern, $body, $matches)) {
                foreach ($matches[1] as $class) {
                    if ($class === 'Select') {
                        continue;
                    }

                    $offenders[] = "{$relative}: {$class}::make('{$column}')";
                }
            }
        }
    }

    // The second half of the premise: the sweep really can see a governed picker.
    expect($boundPickers)->toBeGreaterThan(30);

    expect($offenders)->toBe([], "These render a retire-able catalogue code with a field the\n"
        ."`Select` container binding cannot reach, so the day the operator switches that code off\n"
        ."every record carrying it becomes unsavable with nothing on screen to say so.\n"
        ."Use a plain `Select`, or extend the binding to cover the class:\n  "
        .implode("\n  ", $offenders));
});

it('derives the governed column names from the registry rather than from a list', function () {
    // The tie the sweep asked for: `ValueSets::catalogueWidenedColumns()` is what the SAVING
    // listener widens a column from, and the picker layer has to answer for exactly those columns.
    // Replacing the derivation with a literal — the shape this codebase calls its signature defect
    // — fails here rather than in production, where it would show up as one column silently losing
    // its carve-out.
    $names = CatalogueAwareSelect::governedColumnNames();

    expect(ValueSets::catalogueWidenedColumns())->not->toBeEmpty();

    foreach (array_keys(ValueSets::catalogueWidenedColumns()) as $key) {
        $column = substr($key, strpos($key, '.') + 1);

        // `toHaveKey($key, $value)` would assert the VALUE, not a message — the trap
        // `FieldHelpConformanceTest` documents.
        expect(array_key_exists($column, $names))->toBeTrue(
            "`{$key}` is widened from a catalogue, but `{$column}` is not a column the picker layer knows about."
        );
    }
});
