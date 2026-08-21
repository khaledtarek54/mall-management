<?php

/*
|--------------------------------------------------------------------------
| A catalogue must WIDEN every column it drives
|--------------------------------------------------------------------------
| `IsCodeCatalogue`'s own docblock states the rule — a catalogue owes "a `ValueSets` floor and a
| `CATALOGUE_WIDENED` entry PER COLUMN it drives" — and nothing enforced it. Drop
| `'vendor_documents.type'` from `CATALOGUE_WIDENED` and every shipped test stays green, while an
| operator who adds a document type is OFFERED it by the picker and REFUSED it by the saving
| listener: a button that does nothing, with a `DomainException` in the log and no explanation on
| screen. That is verbatim the 2026-08-18 deposit bug.
|
| `OfferedValuesAreAcceptedValuesConformanceTest` cannot see it BY CONSTRUCTION. It compares
| `allowed()` against `forTable()` — and both call the same `widen()`, so removing the entry makes
| them agree, un-widened, and the gate goes quiet. That is the memory-noted failure mode: *a gate
| derived from the source it checks cannot see what that source omits.*
|
| So this one derives the expectation from the OTHER side — the models on disk — and requires each to
| be named. It is deliberately not a count: a count drifts and gets bumped.
*/

use App\Support\ValueSets;
use Illuminate\Support\Facades\File;

/** @return array<int, class-string> */
function catalogueModelsOnDisk(): array
{
    return collect(File::allFiles(app_path('Models')))
        ->filter(fn ($f) => str_contains($f->getContents(), 'use IsCodeCatalogue;'))
        ->map(fn ($f) => 'App\\Models\\'.$f->getFilenameWithoutExtension())
        ->filter(fn (string $c) => class_exists($c))
        ->values()
        ->all();
}

it('names every catalogue model in CATALOGUE_WIDENED', function () {
    $models = catalogueModelsOnDisk();

    // The premise. Discovery is a string match on the trait's use statement, so a rename would leave
    // this sweeping nothing and reporting a clean run.
    expect($models)->not->toBeEmpty()
        ->and(count($models))->toBeGreaterThanOrEqual(6);

    $widened = collect(ValueSets::catalogueWidenedColumns())
        ->map(fn (array $entry) => $entry[0])
        ->unique()
        ->all();

    $missing = array_values(array_diff($models, $widened));

    expect($missing)->toBe([], implode("\n", [
        'These models use IsCodeCatalogue and widen no column. An operator adding a row would be',
        'OFFERED the value by the picker and REFUSED it by the saving listener — a button that does',
        'nothing, with no explanation on screen:',
        '  '.implode("\n  ", $missing),
    ]));
});

it('points every CATALOGUE_WIDENED entry at a real set and a real reader', function () {
    $broken = [];

    foreach (ValueSets::catalogueWidenedColumns() as $key => [$model, $method]) {
        [$table, $column] = explode('.', $key, 2);

        if (ValueSets::allowed($table, $column) === null) {
            $broken[] = "{$key} is widened but has no SETS floor — the column is unenforced.";
        }

        if (! method_exists($model, $method)) {
            $broken[] = "{$key} reads {$model}::{$method}(), which does not exist.";
        }
    }

    expect($broken)->toBe([], implode("\n", $broken));
});
