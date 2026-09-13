<?php

namespace App\Support\Filament;

use Filament\Actions\Imports\ImportColumn;

/**
 * The ONE seam that decides what an import cell means — a boolean cell is an answer, a numeric cell
 * is a number, or the row is refused naming the field.
 *
 * `ImportColumn::configureUsing` runs at `make()`, so every import column in the app is covered by
 * being one, the idiom `MultiValueFieldIsAnArray` uses on `Select`. **It must be one registration,
 * and this class exists to say so**: `castStateUsing()` is a SINGLE slot on the column, so a second
 * `configureUsing` installing its own cast would silently REPLACE the first — the boolean seam
 * would be disarmed the day the numeric one was registered beside it, with every boolean column
 * back to `(bool) $cell` and nothing red. The dispatch lives here instead, in the order Filament's
 * own `castStateItem()` asks the questions (boolean before numeric); the vocabularies live in
 * `BooleanImportCellIsAnAnswer` and `NumericImportCellIsANumber`. A column that sets its own
 * `castStateUsing()` after `make()` replaces this one, which is what a caller with a better
 * answer should do.
 *
 * An `->array()` column is split and cast per item by Filament before the cast hook runs; the
 * original string is the whole list, so that path is left to Filament.
 */
final class ImportCellCasts
{
    public static function register(): void
    {
        ImportColumn::configureUsing(fn (ImportColumn $column) => $column->castStateUsing(
            fn (ImportColumn $component, mixed $state, mixed $originalState): mixed => match (true) {
                filled($component->getArraySeparator()) => $state,
                $component->isBoolean() => BooleanImportCellIsAnAnswer::answer($originalState),
                $component->isNumeric() => NumericImportCellIsANumber::answer($originalState, $component->getDecimalPlaces()),
                default => $state,
            },
        ));
    }
}
