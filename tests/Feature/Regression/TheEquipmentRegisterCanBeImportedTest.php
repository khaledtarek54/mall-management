<?php

use App\Filament\Imports\EquipmentImporter;
use App\Models\Equipment;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * A mall's equipment register arrives as a spreadsheet, so it can be uploaded (GAP1B-06).
 *
 * The only way in was the form, one asset at a time — so an operator onboarding a second mall
 * either types several hundred rows or does without a register. The second is the likely outcome
 * and the expensive one: no equipment means service plans have nothing to attach to, and the whole
 * preventive side of module 26 stays empty.
 *
 * Driven through `Importer::__invoke()`, the same entry point `ImportCsv` uses per row, so these
 * exercise resolveRecord → validate → fill → save exactly as a real upload does. A test that
 * inspects column RULES and never runs a row is how four faults once sat on the cut-over path
 * behind a green suite (see `LeaseImportExecutesTest`).
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
    $this->other = makeAsset(['code' => 'OTHER']);

    // **The decoy is created FIRST, deliberately.** An unscoped `where('code', 'A-101')` returns
    // the lowest id, so with the right unit created first the scoping test passes on insertion
    // order and proves nothing — measured: removing the `asset_id` clause left it green.
    $this->decoyUnit = makeUnit($this->other, ['code' => 'A-101']);
    $this->unit = makeUnit($this->asset, ['code' => 'A-101']);
    $this->trade = tradeId('hvac');

    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'equipment.csv',
        'file_path' => 'equipment.csv',
        'importer' => EquipmentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => User::factory()->create()->id,
    ]);
});

function importEquipmentRow(array $row): void
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();

    (new EquipmentImporter(test()->import, $columnMap, []))($row);
}

function equipmentRow(array $overrides = []): array
{
    return array_merge([
        'asset_code' => 'MALL',
        'code' => 'CH-01',
        'name_en' => 'Chiller 1',
        'name_ar' => 'مبرد ١',
        'trade_code' => 'hvac',
        'criticality' => Equipment::CRITICAL,
        'unit_code' => 'A-101',
        'location' => 'Roof, north',
        'notes' => 'Imported at cut-over',
    ], $overrides);
}

it('imports a row into the register, resolving its property, trade and unit', function () {
    importEquipmentRow(equipmentRow());

    $equipment = Equipment::where('code', 'CH-01')->first();

    expect($equipment)->not->toBeNull()
        ->and($equipment->asset_id)->toBe($this->asset->id)
        ->and($equipment->trade_id)->toBe($this->trade)
        ->and($equipment->unit_id)->toBe($this->unit->id)
        ->and($equipment->name_ar)->toBe('مبرد ١')
        ->and($equipment->is_active)->toBeTrue();
});

it('corrects a row on a second pass rather than duplicating it', function () {
    // A migrating operator re-uploads a corrected file more often than a clean one. Keyed on
    // (property, code), which is the identity the register itself uses.
    importEquipmentRow(equipmentRow());
    importEquipmentRow(equipmentRow(['name_en' => 'Chiller 1 (north)']));

    expect(Equipment::where('code', 'CH-01')->count())->toBe(1)
        ->and(Equipment::where('code', 'CH-01')->first()->name_en)->toBe('Chiller 1 (north)');
});

it('refuses a trade it does not know, rather than importing equipment with none', function () {
    // The trade routes a job to whoever can do it. Equipment with no trade produces work orders
    // with no trade, which is the defect EG-14 exists to have ended — so an unknown code is a
    // refusal, never a null.
    expect(fn () => importEquipmentRow(equipmentRow(['trade_code' => 'no-such-trade'])))
        ->toThrow(ValidationException::class);

    expect(Equipment::count())->toBe(0);
});

it('attaches a unit from the row\'s OWN property, never the same code next door', function () {
    // A unit code is unique per mall, so an unscoped lookup would silently attach this mall's
    // chiller to the identically-coded shop in another one. The decoy holds the LOWER id (see
    // beforeEach), which is what an unscoped query would return.
    importEquipmentRow(equipmentRow());

    expect(Equipment::where('code', 'CH-01')->first()->unit_id)
        ->toBe($this->unit->id)
        ->not->toBe($this->decoyUnit->id);
});

it('refuses a property this operator cannot see', function () {
    // A real operator restricted to MALL — not a mock. `visibleAssetIds()` reads the signed-in
    // user's assigned set, so faking it would prove nothing about the clamp.
    auth()->login(makeUser('manager', [$this->asset->id]));

    importEquipmentRow(equipmentRow(['asset_code' => 'OTHER', 'unit_code' => null]));

    // An import bypasses the Create/Edit pages where assertAssetInScope() runs, so the clamp has
    // to live in the importer.
    expect(Equipment::where('asset_id', $this->other->id)->count())->toBe(0);

    // **Asserted on the layer that actually refuses.** `Importer::__invoke()` calls
    // `resolveRecord()` BEFORE `validateData()`, so for `asset_code` the validation rule never
    // fires — an out-of-scope code makes resolveRecord return null and the row is skipped. Testing
    // only "no row was written" passes with the clamp deleted, because a null `asset_id` then
    // fails the NOT NULL column instead: right outcome, wrong reason, and no message for the
    // operator. Measured — that mutation survived until this assertion was added.
    $importer = new EquipmentImporter($this->import, ['asset_code' => 'asset_code'], []);

    $data = new ReflectionProperty($importer, 'data');
    $data->setAccessible(true);
    $data->setValue($importer, equipmentRow(['asset_code' => 'OTHER']));

    expect($importer->resolveRecord())->toBeNull();

    // …and resolves for the property they DO hold, so the null above is the clamp and not a
    // resolveRecord that answers null for everything.
    $data->setValue($importer, equipmentRow());
    expect($importer->resolveRecord())->not->toBeNull();
});

it('still imports the property that operator CAN see — the paired control', function () {
    // Without this the refusal above would pass just as happily on an importer that was broken
    // outright, which is exactly the state LeaseImporter was found in.
    auth()->login(makeUser('manager', [$this->asset->id]));

    importEquipmentRow(equipmentRow());

    expect(Equipment::where('code', 'CH-01')->first()?->asset_id)->toBe($this->asset->id);
});
