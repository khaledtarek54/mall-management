<?php

use App\Filament\Imports\UnitOwnershipImporter;
use App\Models\UnitOwnership;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * The sold units can be loaded at cut-over (GAP1B-06).
 *
 * Module 37's whole population arrived one form at a time, and a mall that sold floors has
 * hundreds. The cost is not the typing: `BillUnitOwnershipsService` is SCHEDULED, so every
 * ownership missing from the register is an owner nobody bills, month after month, reported as an
 * unremarkable `skipped`.
 *
 * Driven through `Importer::__invoke()`, the entry point `ImportCsv` uses per row.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
    $this->other = makeAsset(['code' => 'OTHER']);

    // The decoy FIRST, so an unscoped unit lookup would return IT — otherwise the scoping
    // assertion passes on insertion order and proves nothing.
    $this->decoyUnit = makeUnit($this->other, ['code' => 'C-15']);
    $this->unit = makeUnit($this->asset, ['code' => 'C-15']);

    $this->owner = makeTenant(['email' => 'owner@family.test']);

    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'owners.csv',
        'file_path' => 'owners.csv',
        'importer' => UnitOwnershipImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => User::factory()->create()->id,
    ]);
});

function importOwnershipRow(array $row): void
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();

    (new UnitOwnershipImporter(test()->import, $columnMap, []))($row);
}

function ownershipRow(array $overrides = []): array
{
    return array_merge([
        'asset_code' => 'MALL',
        'unit_code' => 'C-15',
        'owner_email' => 'owner@family.test',
        'tenure_type' => 'freehold',
        'status' => 'handed_over',
        'management_mode' => 'self_occupied',
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'handover_date' => '2026-01-01',
        'started_at' => '2026-01-01',
    ], $overrides);
}

it('imports an ownership, resolving its property, unit and owner', function () {
    importOwnershipRow(ownershipRow());

    $ownership = UnitOwnership::first();

    expect($ownership)->not->toBeNull()
        ->and($ownership->asset_id)->toBe($this->asset->id)
        ->and($ownership->unit_id)->toBe($this->unit->id)
        ->and($ownership->tenant_id)->toBe($this->owner->id)
        ->and((float) $ownership->ownership_share_pct)->toBe(100.0)
        ->and($ownership->currency)->toBe('EGP');
});

it('takes the unit from the row\'s OWN property, never the same code next door', function () {
    // A unit code is unique per mall. The decoy holds the lower id, which is what an unscoped
    // lookup would return — so this fails if the `asset_id` clause is dropped.
    importOwnershipRow(ownershipRow());

    expect(UnitOwnership::first()->unit_id)
        ->toBe($this->unit->id)
        ->not->toBe($this->decoyUnit->id);
});

it('records a CO-OWNED unit as two tenures with their own shares', function () {
    // Two owners at 50 each is the ordinary Egyptian shape, and ignoring the share is SW-220 —
    // where a co-owned unit counted once at full area and the neighbour was under-charged.
    $second = makeTenant(['email' => 'partner@family.test']);

    importOwnershipRow(ownershipRow(['ownership_share_pct' => 50]));
    importOwnershipRow(ownershipRow(['owner_email' => 'partner@family.test', 'ownership_share_pct' => 50]));

    expect(UnitOwnership::count())->toBe(2)
        ->and(UnitOwnership::sum('ownership_share_pct'))->toEqual(100)
        ->and(UnitOwnership::pluck('tenant_id')->all())->toEqualCanonicalizing([$this->owner->id, $second->id]);
});

it('corrects a tenure on a second pass rather than duplicating it', function () {
    importOwnershipRow(ownershipRow());
    importOwnershipRow(ownershipRow(['assessment_basis' => 'participation', 'participation_pct' => 3.5]));

    expect(UnitOwnership::count())->toBe(1)
        ->and(UnitOwnership::first()->assessment_basis->value ?? UnitOwnership::first()->assessment_basis)
        ->toBe('participation');
});

it('refuses an owner who is not already a party on file', function () {
    // An owner IS a `tenants` row — the counterparty a mall bills is one register whether the
    // money is rent or an assessment. Creating a second party record for somebody already on file
    // is how a portfolio ends up with two of everyone.
    expect(fn () => importOwnershipRow(ownershipRow(['owner_email' => 'nobody@nowhere.test'])))
        ->toThrow(ValidationException::class);

    expect(UnitOwnership::count())->toBe(0);
});

it('refuses a property this operator cannot see', function () {
    auth()->login(makeUser('manager', [$this->asset->id]));

    importOwnershipRow(ownershipRow(['asset_code' => 'OTHER']));

    expect(UnitOwnership::where('asset_id', $this->other->id)->count())->toBe(0);

    // Asserted on the layer that actually refuses: `__invoke()` calls `resolveRecord()` BEFORE
    // validation, so an out-of-scope code is skipped there and the column rule never fires.
    $importer = new UnitOwnershipImporter($this->import, ['asset_code' => 'asset_code'], []);
    $data = new ReflectionProperty($importer, 'data');
    $data->setAccessible(true);

    $data->setValue($importer, ownershipRow(['asset_code' => 'OTHER']));
    expect($importer->resolveRecord())->toBeNull();

    // …and resolves for the property they DO hold, so the null above is the clamp rather than a
    // resolveRecord that answers null for everything.
    $data->setValue($importer, ownershipRow());
    expect($importer->resolveRecord())->not->toBeNull();
});
