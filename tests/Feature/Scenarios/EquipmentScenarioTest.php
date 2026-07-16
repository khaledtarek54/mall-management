<?php

use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;

/**
 * The maintainable-asset register (module 26, FR-PPM-03/04/05): codes unique per property,
 * sub-code trees, compatible spare parts, and the isolation/acyclicity rules that keep the
 * tree sane.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'EQA']);
});

function makeEquipment(int $assetId, array $attrs = []): Equipment
{
    return Equipment::create(array_merge([
        'asset_id' => $assetId,
        'code' => 'ESC-01',
        'name_en' => 'Main escalator',
        'name_ar' => 'السلم الكهربائي الرئيسي',
        'category' => 'elevator',
    ], $attrs));
}

/* ---- FR-PPM-03: a unique code per machine ------------------------------ */

it('requires the code to be unique within a property', function () {
    makeEquipment($this->asset->id, ['code' => 'ESC-01']);

    expect(fn () => makeEquipment($this->asset->id, ['code' => 'ESC-01']))
        ->toThrow(QueryException::class);
});

it('lets two properties reuse the same code', function () {
    // Per-property uniqueness is the point: without it every mall would need a prefix
    // baked into each code, which is what asset_id is for.
    $other = makeAsset(['code' => 'EQB']);

    makeEquipment($this->asset->id, ['code' => 'ESC-01']);
    $twin = makeEquipment($other->id, ['code' => 'ESC-01']);

    expect($twin->exists)->toBeTrue();
    expect(Equipment::where('code', 'ESC-01')->count())->toBe(2);
});

/* ---- FR-PPM-04: sub-codes ---------------------------------------------- */

it('nests components under a parent as sub-codes', function () {
    $escalator = makeEquipment($this->asset->id, ['code' => 'ESC-01']);
    $motor = makeEquipment($this->asset->id, ['code' => 'ESC-01-MOT', 'parent_id' => $escalator->id, 'name_en' => 'Motor']);
    $handrail = makeEquipment($this->asset->id, ['code' => 'ESC-01-HND', 'parent_id' => $escalator->id, 'name_en' => 'Handrail']);

    expect($escalator->children()->pluck('code')->all())->toEqualCanonicalizing(['ESC-01-MOT', 'ESC-01-HND']);
    expect($motor->parent->code)->toBe('ESC-01');
    expect(Equipment::roots()->pluck('code')->all())->toBe(['ESC-01']);
    expect($handrail->ancestorIds())->toBe([$escalator->id]);
});

it('supports a deeper component tree', function () {
    $a = makeEquipment($this->asset->id, ['code' => 'CH-01']);
    $b = makeEquipment($this->asset->id, ['code' => 'CH-01-PMP', 'parent_id' => $a->id]);
    $c = makeEquipment($this->asset->id, ['code' => 'CH-01-PMP-SEAL', 'parent_id' => $b->id]);

    expect($c->ancestorIds())->toBe([$b->id, $a->id]);
    expect($a->selfAndDescendantIds())->toEqualCanonicalizing([$a->id, $b->id, $c->id]);
});

it('promotes children to roots rather than deleting them with the parent', function () {
    // nullOnDelete, not cascade — a component's maintenance history must outlive a
    // re-organisation of the tree above it.
    $parent = makeEquipment($this->asset->id, ['code' => 'ESC-01']);
    $child = makeEquipment($this->asset->id, ['code' => 'ESC-01-MOT', 'parent_id' => $parent->id]);

    $parent->forceDelete();

    expect($child->fresh())->not->toBeNull();
    expect($child->fresh()->parent_id)->toBeNull();
});

/* ---- the tree's integrity rules ---------------------------------------- */

it('refuses a parent in another property', function () {
    // A cross-property parent would let Mall A's escalator own Mall B's motor, and the
    // child would surface in the wrong property's tree. The DB can't express it.
    $other = makeAsset(['code' => 'EQC']);
    $foreign = makeEquipment($other->id, ['code' => 'ESC-99']);

    expect(fn () => makeEquipment($this->asset->id, ['code' => 'ESC-01-MOT', 'parent_id' => $foreign->id]))
        ->toThrow(InvalidArgumentException::class, 'another property');
});

it('refuses to make equipment its own parent', function () {
    $e = makeEquipment($this->asset->id);

    expect(fn () => $e->update(['parent_id' => $e->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to parent equipment under its own sub-code', function () {
    // Would detach the whole branch from every root and hang any naive tree walk.
    $parent = makeEquipment($this->asset->id, ['code' => 'ESC-01']);
    $child = makeEquipment($this->asset->id, ['code' => 'ESC-01-MOT', 'parent_id' => $parent->id]);

    expect(fn () => $parent->update(['parent_id' => $child->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a parent that does not exist', function () {
    expect(fn () => makeEquipment($this->asset->id, ['parent_id' => 99999]))
        ->toThrow(InvalidArgumentException::class);
});

it('terminates the ancestor walk even if the data already holds a cycle', function () {
    // Guards can only stop cycles created through the model. If one ever exists (a direct
    // DB edit, an import), a walk must not hang the request.
    $a = makeEquipment($this->asset->id, ['code' => 'A']);
    $b = makeEquipment($this->asset->id, ['code' => 'B', 'parent_id' => $a->id]);
    DB::table('equipment')->where('id', $a->id)->update(['parent_id' => $b->id]); // bypasses the model

    expect($a->fresh()->ancestorIds())->toEqualCanonicalizing([$b->id, $a->id]);
    expect($a->fresh()->selfAndDescendantIds())->toEqualCanonicalizing([$a->id, $b->id]);
});

/* ---- FR-PPM-05: compatible spare parts --------------------------------- */

it('links compatible spare parts from the shared catalog', function () {
    $escalator = makeEquipment($this->asset->id);
    $seal = InventoryItem::create(['sku' => 'PMP-SEAL', 'name' => 'Pump seal', 'unit' => 'pc', 'unit_cost' => 120]);
    $belt = InventoryItem::create(['sku' => 'ESC-BELT', 'name' => 'Handrail belt', 'unit' => 'pc', 'unit_cost' => 900]);

    $escalator->inventoryItems()->sync([$seal->id, $belt->id]);

    expect($escalator->fresh()->inventoryItems()->pluck('sku')->all())->toEqualCanonicalizing(['PMP-SEAL', 'ESC-BELT']);
    // The catalog is shared, so the same part fits machines in other malls too.
    expect($seal->fresh()->exists)->toBeTrue();
});

/* ---- the optional accounting twin -------------------------------------- */

it('optionally links a machine to its fixed-asset record', function () {
    $fa = FixedAsset::create([
        'asset_id' => $this->asset->id, 'name' => 'Escalator', 'tag' => 'FA-ESC-01',
        'acquisition_date' => '2025-01-01', 'acquisition_cost' => 500000, 'useful_life_months' => 120,
    ]);
    $e = makeEquipment($this->asset->id, ['fixed_asset_id' => $fa->id]);

    expect($e->fixedAsset->tag)->toBe('FA-ESC-01');
    // The link is optional: plenty of maintainable kit is never capitalised.
    expect(makeEquipment($this->asset->id, ['code' => 'PMP-01'])->fixed_asset_id)->toBeNull();
});

/* ---- defaults ----------------------------------------------------------- */

it('defaults is_active to true so a NOT-NULL column never receives null', function () {
    expect(makeEquipment($this->asset->id)->is_active)->toBeTrue();
});

/* ---- RBAC + property scoping ------------------------------------------- */

it('gates the register on preventive_maintenance permissions', function () {
    $this->actingAs(makeUser('operations'));
    expect(EquipmentResource::canViewAny())->toBeTrue();
    expect(EquipmentResource::canCreate())->toBeTrue();

    // Leasing has no business in the plant room.
    $this->actingAs(makeUser('leasing'));
    expect(EquipmentResource::canViewAny())->toBeFalse();
});

it('scopes the register to the current property', function () {
    $other = makeAsset(['code' => 'EQD']);
    makeEquipment($this->asset->id, ['code' => 'A-ESC']);
    makeEquipment($other->id, ['code' => 'B-ESC']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($this->asset, function () {
        expect(scopedResourceQuery(EquipmentResource::class)->pluck('code')->all())
            ->toContain('A-ESC')->not->toContain('B-ESC');
    });
});

it('rejects an out-of-scope asset_id', function () {
    $other = makeAsset(['code' => 'EQE']);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    EquipmentResource::assertAssetInScope($this->asset->id);

    expect(fn () => EquipmentResource::assertAssetInScope($other->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
