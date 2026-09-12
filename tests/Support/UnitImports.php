<?php

namespace Tests\Support;

use App\Filament\Imports\UnitImporter;
use App\Models\Asset;
use App\Models\Unit;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Drive ONE row through `UnitImporter::__invoke()` — the per-row seam `ImportCsv` calls, which
 * remaps, casts, VALIDATES, runs the lifecycle hooks, resolves the record and saves. Setting a
 * column by hand would be a fixture writing what no door writes, and green over dead code.
 *
 * Extracted from `AnExportedCellIsAValueNotARecordTest` on its second call site (the net-area test,
 * 2026-09-12). Keyed by LABEL, not name-to-name: an identity map makes the CSV header identical to
 * the column name, so a test would pass whether Filament keys validation data by column name or by
 * CSV header — the very thing a `DataAwareRule` depends on. `remapData()` leaves BOTH key sets on
 * `$this->data`, which is what makes `$this->data['asset_code']` reachable from a rule whose column
 * was mapped from a header called "Code".
 *
 * Returns the unit on success, or the refusal SENTENCE — a validation message, or the
 * `RowImportFailedException` a lifecycle hook throws (the only exception `ImportCsv` writes into the
 * failed-rows file in words).
 *
 * A key ABSENT from `$row` models an UNMAPPED column (`remapData()` skips it, so the record keeps
 * what it holds); a key present with `''` models a mapped BLANK cell, which Filament casts to null
 * and fills.
 */
final class UnitImports
{
    /**
     * @param  array<string, mixed>  $row  keyed by column NAME; remapped to labels here
     */
    public static function row(Asset $asset, array $row): Unit|string
    {
        $map = [];

        foreach (UnitImporter::getColumns() as $column) {
            $map[$column->getName()] = (string) $column->getLabel();
        }

        $unitCode = $row['code'];
        $row = collect($row)->mapWithKeys(fn ($value, $name) => [$map[$name] ?? $name => $value])->all();

        $import = Import::create([
            'completed_at' => now(),
            'file_name' => 'units.csv',
            'file_path' => 'units.csv',
            'importer' => UnitImporter::class,
            'processed_rows' => 0,
            'total_rows' => 1,
            'successful_rows' => 0,
            'user_id' => auth()->id(),
        ]);

        $importer = new UnitImporter($import, $map, []);

        try {
            $importer($row);
        } catch (ValidationException $e) {
            return implode(' ', Arr::flatten($e->errors()));
        } catch (RowImportFailedException $e) {
            return $e->getMessage();
        }

        return Unit::where('asset_id', $asset->id)->where('code', $unitCode)->sole();
    }
}
