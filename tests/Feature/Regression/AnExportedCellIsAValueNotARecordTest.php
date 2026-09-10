<?php

use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Filament\Exports\UnitExporter;
use App\Filament\Imports\UnitImporter;
use App\Models\Asset;
use App\Models\Floor;
use App\Models\Unit;
use App\Support\ReportCsv;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader;
use League\Csv\Writer;
use Livewire\Livewire;
use Tests\Support\ExportCells;

/**
 * The units CSV printed a JSON dump of the Floor row where the floor should have been.
 *
 * Reported from the panel, exactly like this:
 *
 *     {"id":2,"asset_id":2,"code":"G","name":"Ground","level":0,"created_at":…,"updated_at":…}
 *
 * `ExportColumn::make('floor')` named the relation rather than an attribute on it, and Filament
 * resolves a relationship only when the name contains a dot — so the cell WAS the Floor model and
 * the writer stringified it through `Model::__toString()`. It is `floor.code` now, which is what
 * the units table shows in that column and the value a re-import would join on.
 *
 * Rendered through `ExportCells::row()`, i.e. the exporter's own `__invoke()` — the seam the export
 * job calls per record. A test on a column's `getState()` skips the formatting the writer applies
 * and would have passed against a Model just as happily.
 *
 * The structural twin is `ExportedCellsAreValuesConformanceTest`, which sweeps all nine exporters
 * with no data at all; this one proves the rendered bytes for the column that was reported.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'XP', 'name' => 'Export Mall']);
    $this->ground = Floor::create([
        'asset_id' => $this->asset->id,
        'code' => 'G',
        'name' => 'Ground',
        'level' => 0,
    ]);
    $this->unit = makeUnit($this->asset, ['code' => 'A-1', 'floor_id' => $this->ground->id]);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
});

it('exports the floor as its code, not as a JSON dump of the floor row', function () {
    $row = ExportCells::row(UnitExporter::class, $this->unit->refresh());

    // The WHOLE cell, not a substring: "G" is contained in the JSON blob too, so a toContain()
    // assertion would pass on exactly the output being refused.
    expect($row)->toHaveKey('floor.code')
        ->and($row['floor.code'])->toBe('G')
        ->and($row)->not->toHaveKey('floor');
});

it('renders every cell of the units export as something a spreadsheet can show', function () {
    $row = ExportCells::row(UnitExporter::class, $this->unit->refresh());

    $unusable = [];

    foreach ($row as $name => $value) {
        if ($reason = ExportCells::unusable($value)) {
            $unusable[] = "{$name} renders {$reason}";
        }
    }

    expect($row)->not->toBeEmpty('the exporter produced no cells')
        ->and($unusable)->toBe([], "an exported cell is not a value:\n- ".implode("\n- ", $unusable));
});

/**
 * `App\Support\ReportCsv` — the 20 deliverable report pages, 6 register CSVs and scheduled delivery — writes
 * and reads with the escape character OFF, at both ends.
 *
 * PHP's default `$escape` is a backslash, which is not RFC 4180: measured, `a\"b` was written with
 * its enclosure left undoubled, because the backslash was taken as escaping it, and `fgetcsv`
 * returned `a\\b"`. A report an accountant re-imports has to survive the round trip, so the READ
 * side (`ReportCsv::parse()`) had to move with the write side — two importers were hard-coding
 * `'\\'` and two more passing nothing at all, which is a PHP 8.4 deprecation now and a silent
 * behaviour change in PHP 9.
 *
 * **Scope, stated because it is narrower than this file's title suggests:** the nine Filament
 * exporters do NOT use this writer — they use `League\Csv\Writer`, whose escape Filament v4.11.8
 * never sets and offers no hook for. That gap is pinned by the contract test below rather than
 * fixed, and the reasoning is there.
 */
it('writes a report CSV that reads back as exactly what was exported', function () {
    $values = ['plain', 'has,comma', 'has"quote', 'trailing\\', 'C:\\path\\', 'a\\"b', 'عربي'];

    $csv = ReportCsv::toString(['heading'], [$values]);

    // Read back through `ReportCsv::parse()` — the OTHER half of the same control. Reading with a
    // hand-written `fgetcsv(..., '')` would leave the test green if `parse()`'s escape ever drifted
    // back, which is exactly what a test named for "one function so the pair cannot drift" must not
    // do. The BOM belongs to the file, not to a line, so it is stripped before splitting.
    $lines = preg_split('/\R/', trim(substr($csv, 3)));

    expect(ReportCsv::parse($lines[1]))->toBe($values)
        ->and(ReportCsv::parse($lines[0]))->toBe(['heading']);
});

/**
 * The whole way through: press the button, read the bytes.
 *
 * The two tests above render through `ExportCells::row()`, which is the exporter's own seam but
 * still not the operator's — this drives the table's `ExportAction` and reads what lands on disk.
 * Worth having as well as the unit-level pair, because everything between them (the column map the
 * modal submits, the writer, the file assembly) is where the JSON blob actually became visible; the
 * exports queue runs `sync` in tests, so the job completes inline.
 */
it('produces a units CSV whose floor column is a code and not a record', function () {
    asTenant($this->asset, function () {
        Livewire::test(ListUnits::class)
            ->assertTableActionVisible('export')
            ->callAction(TestAction::make('export')->table());
    });

    $export = Export::latest('id')->first();

    expect($export)->not->toBeNull('pressing Export produced no export record');

    // `getFileDisk()` already returns the Filesystem, not the disk's name.
    $disk = $export->getFileDisk();
    $files = collect($disk->files($export->getFileDirectory()))
        ->filter(fn (string $path): bool => str_ends_with($path, '.csv'));

    expect($files)->not->toBeEmpty('the export wrote no CSV');

    $csv = $disk->get($files->first());

    // The reported symptom, asserted on the BYTES an operator opens: a JSON object carrying an
    // `id` key is what a stringified model looks like in a cell, in any column.
    expect($csv)->not->toMatch('/[{\[]""?id""?:/')
        ->and($csv)->toContain('A-1');
});

/**
 * THE ROUND TRIP, which is what `floor.code` is chosen for — and the door at the other end was
 * broken.
 *
 * `units.floor` was dropped by `2026_08_10_160000_create_floors_and_move_units_onto_them`, and
 * `UnitImporter`'s column was never moved onto the register that replaced it: no
 * `fillRecordUsing`, no `relationship()`, so `ImportColumn::fillRecord()` fell through to
 * `data_set($record, 'floor', $state)`. A Model is `ArrayAccess`, so that set an ATTRIBUTE named
 * for a column that does not exist, and the insert died with a raw
 * `SQLSTATE[42S22]: Unknown column 'floor'` — on EVERY row, and on a BLANK cell too, since
 * `isBlankStateIgnored()` defaults to false so the fill still ran.
 *
 * **The export fix is what made it reachable.** Filament writes the export header row from the
 * column LABELS, and `ImportColumn::getGuesses()` unshifts its own label — both sides resolve
 * `Floor` / «الطابق», so the mapping screen auto-guesses the exported Floor column straight onto
 * the broken one. Fixing one door and leaving the other is how a file that exports cleanly fails
 * every row going back in.
 *
 * Driven through `Importer::__invoke()` — the per-row seam `ImportCsv` calls, which remaps, casts,
 * VALIDATES, resolves the record and saves. Setting `floor_id` by hand would be a fixture writing
 * a column no door writes, and green over dead code.
 */
function importUnitRow(Asset $asset, array $row): Unit|string
{
    // Keyed by LABEL, not name-to-name. An identity map makes the CSV header identical to the
    // column name, so the test would pass whether Filament keys validation data by column name or
    // by CSV header — the very thing the DataAwareRule depends on. `remapData()` leaves BOTH key
    // sets on `$this->data`, which is what makes `$this->data['asset_code']` reachable from a rule
    // whose column was mapped from a header called "Code".
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
    }

    return Unit::where('asset_id', $asset->id)->where('code', $unitCode)->sole();
}

it('re-imports the floor code it exported, onto the floor register', function () {
    $exported = ExportCells::row(UnitExporter::class, $this->unit->refresh())['floor.code'];

    $imported = importUnitRow($this->asset, [
        'asset_code' => $this->asset->code,
        'code' => 'B-2',
        'floor' => $exported,
        'area_sqm' => '55',
    ]);

    expect($imported)->toBeInstanceOf(Unit::class, "the import refused the row: {$imported}")
        ->and($imported->floor_id)->toBe($this->ground->id)
        ->and($imported->floor->code)->toBe($exported);
});

it('refuses a floor the property does not have, in words rather than as SQL', function () {
    $refusal = importUnitRow($this->asset, [
        'asset_code' => $this->asset->code,
        'code' => 'B-3',
        'floor' => 'B7',
        'area_sqm' => '55',
    ]);

    expect($refusal)->toBeString('the row was accepted with a floor that does not exist')
        ->and($refusal)->toContain('B7')
        // The defect was a database error string reaching the operator.
        ->and($refusal)->not->toContain('SQLSTATE')
        ->and($refusal)->not->toContain('Unknown column');
});

it('imports a unit with no floor at all, which is the blank cell that also used to fail', function () {
    $imported = importUnitRow($this->asset, [
        'asset_code' => $this->asset->code,
        'code' => 'B-4',
        'floor' => '',
        'area_sqm' => '55',
    ]);

    expect($imported)->toBeInstanceOf(Unit::class, "the import refused a blank floor: {$imported}")
        ->and($imported->floor_id)->toBeNull();
});

/**
 * The gap this fix does NOT close, pinned so it cannot be forgotten or silently change.
 *
 * The nine Filament exporters do not use `ReportCsv` at all — `ExportCsv`/`PrepareCsvExport` build
 * `League\Csv\Writer::from(new SplTempFileObject)` and set only the delimiter, so the escape stays
 * PHP's backslash. **Filament v4.11.8 contains no reference to `escape` anywhere under `Exports/`**:
 * the writer is a local variable inside two `handle()` methods totalling 242 lines, reachable only
 * by subclassing both jobs and wiring `->job()` at all 17 export call sites — 242 lines of vendor
 * logic copied into the app, drifting on every upgrade.
 *
 * **The shape, measured rather than reasoned, because the obvious guesses were both wrong.** A
 * TRAILING backslash is fine: `C:\path\` is written `"C:\path\"`, which every RFC reader — Excel
 * included, and `ReportCsv::parse()` — reads back correctly. What breaks is a backslash immediately
 * before a QUOTE: League writes `a\"b` as `"a\"b"` with the enclosure left UNDOUBLED, which is not
 * RFC 4180, so an RFC reader takes the quote as closing the field and every column after it shifts.
 * Note it round-trips through League's OWN reader, which un-escapes symmetrically — so the defect is
 * invisible if you test the writer against the matching reader, and only appears against the
 * readers that actually open these files.
 *
 * **Exposure, also measured:** across the demo database's tenant, unit, vendor, property, invoice
 * and request text columns there are **zero** values containing a backslash at all, let alone one
 * before a quote. So this is latent, not live, and the proportionate answer is to pin the upstream
 * behaviour — exactly as `FilamentActionDispatchContractTest` pins the action-dispatch contract
 * this codebase relies on — rather than to fork two vendor jobs for it.
 *
 * **This test is the trigger.** It goes red the day League or Filament starts writing RFC-compliant
 * output, which is the day this docblock and the gap should go and `ReportCsv`'s two halves can note
 * that all three writers finally agree.
 */
it('records that Filament\'s export writer still writes non-RFC output for a backslashed quote', function () {
    // The one shape that breaks, and two controls that must NOT — or the record would overstate
    // the gap and get "fixed" by someone chasing a defect that is not there.
    // `'a\\"b'` is a\"b — ONE backslash then a quote, the shape that breaks.
    $breaks = ['a\\"b', 'next', 'third'];
    $fine = [['C:\\path\\', 'next', 'third'], ['has"quote', 'next', 'third']];

    // Constructed exactly as `ExportCsv::handle()` does it: a fresh SplTempFileObject, delimiter
    // set, escape untouched — so this measures Filament's writer and not a strawman.
    $write = function (array $values): string {
        $writer = Writer::from(new SplTempFileObject);
        $writer->setDelimiter(UnitExporter::getCsvDelimiter());
        $writer->insertOne($values);

        return trim($writer->toString());
    };

    // Read the way the things that actually open these files read: escape off (RFC 4180). That is
    // Excel, and since this change it is `ReportCsv::parse()` too.
    expect(ReportCsv::parse($write($breaks)) === $breaks)
        ->toBeFalse('Filament\'s export writer now produces RFC-compliant output for a backslash '
            .'before a quote — the gap recorded here has closed, so delete this test and its '
            .'docblock and note in ReportCsv that all three writers agree');

    foreach ($fine as $values) {
        expect(ReportCsv::parse($write($values)))
            ->toBe($values);
    }
});

/**
 * The escape change asserted against a SERVICE that got it, not just against the writer.
 *
 * Three importers were rerouted onto `ReportCsv::parse()` and none of their fixtures contains a
 * backslash, so the improvement was real and asserted nowhere. Measured: under PHP's default
 * backslash escape, `"C:\path\",next` is ONE MERGED FIELD — the trailing backslash eats the
 * delimiter — so every column after it shifts by one. On a bank statement that means the amount
 * column is read from the description's neighbour, which is a silent misparse rather than an error.
 */
it('parses a bank statement line whose text ends in a backslash without swallowing the next column',
    function () {
        $line = '2026-09-10,"C:\\share\\",1234.50';

        // The shape the old escape produced, stated so the tooth cannot be mistaken for a tautology.
        expect(str_getcsv($line, ',', '"', '\\'))->toHaveCount(2)
            ->and(ReportCsv::parse($line))->toBe(['2026-09-10', 'C:\\share\\', '1234.50']);
    });
