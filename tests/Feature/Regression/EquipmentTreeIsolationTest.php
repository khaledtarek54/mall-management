<?php

use App\Filament\Admin\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\EditEquipment;
use App\Models\Equipment;
use App\Models\FixedAsset;
use App\Models\Unit;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Regression guards — two property-isolation holes in the Equipment register's first cut,
 * both found by probing the register rather than by the tests, which passed throughout.
 *
 * R1 — a PARENT could walk to another property and leave its sub-codes behind. The
 *      same-property rule only fires on the CHILD's save (it checks the child's parent), so
 *      moving the parent bypassed it entirely: Mall A's motor left hanging off Mall B's
 *      escalator, and surfacing in the wrong property's register (the table renders
 *      `parent.code`).
 *
 * R2 — the dependent pickers (parent / unit / fixed asset) were keyed on the raw,
 *      client-supplied `asset_id`. It is ->live() and enabled in All-Properties mode, so a
 *      crafted Livewire request could point it at a property outside the user's set and
 *      enumerate that property's units, equipment codes and fixed assets. The option list
 *      renders long before assertAssetInScope() runs at save, so the refusal came too late.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mine = makeAsset(['code' => 'ETA']);
    $this->theirs = makeAsset(['code' => 'ETB']);
});

function equip(int $assetId, array $attrs = []): Equipment
{
    return Equipment::create(array_merge([
        'asset_id' => $assetId, 'code' => 'ESC-01', 'name_en' => 'Escalator', 'name_ar' => 'سلم',
    ], $attrs));
}

/* ---- R1: a tree may never straddle two properties ---------------------- */

it('refuses to move equipment that has sub-codes to another property', function () {
    $parent = equip($this->mine->id, ['code' => 'ESC-01']);
    equip($this->mine->id, ['code' => 'ESC-01-MOT', 'parent_id' => $parent->id]);

    expect(fn () => $parent->update(['asset_id' => $this->theirs->id]))
        ->toThrow(InvalidArgumentException::class, 'sub-codes');

    expect((int) $parent->fresh()->asset_id)->toBe($this->mine->id);
});

it('leaves no parent and child in different properties', function () {
    // The invariant behind R1, stated directly: whatever the edit path, a child's property
    // always equals its parent's.
    $parent = equip($this->mine->id, ['code' => 'ESC-01']);
    $child = equip($this->mine->id, ['code' => 'ESC-01-MOT', 'parent_id' => $parent->id]);

    rescue(fn () => $parent->update(['asset_id' => $this->theirs->id]));
    rescue(fn () => $child->update(['asset_id' => $this->theirs->id]));

    expect((int) $child->fresh()->asset_id)->toBe((int) $parent->fresh()->asset_id);
});

it('counts a soft-deleted sub-code when refusing the move', function () {
    // A trashed child is still a child: restore it later and it would surface in the wrong
    // property. The tree walks (ancestorIds/selfAndDescendantIds) use withTrashed for the
    // same reason, so the move guard must too.
    $parent = equip($this->mine->id, ['code' => 'ESC-01']);
    $child = equip($this->mine->id, ['code' => 'ESC-01-MOT', 'parent_id' => $parent->id]);
    $child->delete();

    expect($child->fresh()->trashed())->toBeTrue();
    expect(fn () => $parent->update(['asset_id' => $this->theirs->id]))
        ->toThrow(InvalidArgumentException::class, 'sub-codes');

    expect((int) $parent->fresh()->asset_id)->toBe($this->mine->id);
});

it('still allows moving a standalone machine between properties', function () {
    // The guard must bite only on trees — a childless machine is free to be re-filed.
    $lone = equip($this->mine->id, ['code' => 'PMP-01']);

    $lone->update(['asset_id' => $this->theirs->id]);

    expect((int) $lone->fresh()->asset_id)->toBe($this->theirs->id);
});

it('allows moving a leaf once it is detached from its parent', function () {
    $parent = equip($this->mine->id, ['code' => 'ESC-01']);
    $child = equip($this->mine->id, ['code' => 'ESC-01-MOT', 'parent_id' => $parent->id]);

    // Detach + move in one save: it becomes a root in the new property, which is legal.
    $child->update(['parent_id' => null, 'asset_id' => $this->theirs->id]);

    expect((int) $child->fresh()->asset_id)->toBe($this->theirs->id);
    expect($child->fresh()->parent_id)->toBeNull();
});

/* ---- R2: the pickers must not enumerate an invisible property ---------- */

it('offers no options from a property the user cannot see, even if asset_id is tampered', function () {
    // Populate the property the user must not learn about.
    equip($this->theirs->id, ['code' => 'SECRET-ESC', 'name_en' => 'Secret escalator']);
    Unit::create(['asset_id' => $this->theirs->id, 'code' => 'SECRET-U1', 'category' => 'retail', 'area_sqm' => 30, 'status' => 'vacant']);
    FixedAsset::create([
        'asset_id' => $this->theirs->id, 'name' => 'Secret chiller', 'tag' => 'SECRET-FA',
        'acquisition_date' => '2025-01-01', 'acquisition_cost' => 1000, 'useful_life_months' => 60,
    ]);

    $all = ensureAllPropertiesAsset();
    $this->actingAs(makeUser('operations', [$this->mine->id])); // assigned to `mine` only

    asTenant($all, function () {
        $form = Livewire::test(CreateEquipment::class)
            // Tamper: point the live asset_id at a property outside the user's set.
            ->fillForm(['asset_id' => $this->theirs->id]);

        foreach (['parent_id', 'unit_id', 'fixed_asset_id'] as $field) {
            $form->assertFormFieldExists($field, fn ($f) => $f->getOptions() === []);
        }
    });
});

it('does not leak whether a code exists in a property the user cannot see', function () {
    // Subtler than the pickers: Laravel runs every field rule in ONE pass before any
    // mutate hook, and Rule::unique compiles to a raw query that Filament's tenancy scope
    // never touches — so assertAssetInScope() fires too late to help. Keyed on the raw
    // client asset_id, the rule answered "is this code taken in <invisible property>?".
    // The write was refused either way; the tell was `code` erroring or not.
    equip($this->theirs->id, ['code' => 'SECRET-CH-01']);

    $all = ensureAllPropertiesAsset();
    $this->actingAs(makeUser('operations', [$this->mine->id]));

    asTenant($all, function () {
        $errorsFor = function (string $code) {
            $component = Livewire::test(CreateEquipment::class)
                ->fillForm([
                    'asset_id' => $this->theirs->id, // tampered: outside the user's set
                    'code' => $code,
                    'name_en' => 'Probe',
                    'name_ar' => 'فحص',
                ])
                ->call('create');

            return array_keys($component->errors()->toArray());
        };

        // A real code and a nonsense one must be indistinguishable.
        expect($errorsFor('SECRET-CH-01'))->toBe($errorsFor('NOPE-99'));
    });

    expect(Equipment::where('code', 'NOPE-99')->exists())->toBeFalse();
});

/* ---- the register must be maintainable at all ------------------------- */

it('exposes delete, force-delete and restore so a machine is not immortal', function () {
    // The model soft-deletes and the table ships a TrashedFilter, but the resource had no
    // delete/restore action anywhere — the filter could never match a row, and a typo'd
    // code was burned forever (equipment_asset_code_unique counts trashed rows).
    $e = equip($this->mine->id, ['code' => 'TYPO-01']);
    $this->actingAs(makeUser('super_admin', [$this->mine->id]));

    asTenant($this->mine, function () use ($e) {
        Livewire::test(EditEquipment::class, ['record' => $e->id])
            ->assertActionExists('delete')
            ->assertActionExists('forceDelete')
            ->assertActionExists('restore');
    });
});

it('frees a burned code once the record is force-deleted', function () {
    // The unique index counts trashed rows, so soft-delete alone does not free the code —
    // which is exactly why ForceDeleteAction has to exist.
    $e = equip($this->mine->id, ['code' => 'TYPO-02']);
    $e->delete();

    expect(fn () => equip($this->mine->id, ['code' => 'TYPO-02']))
        ->toThrow(Illuminate\Database\QueryException::class);

    $e->forceDelete();

    expect(equip($this->mine->id, ['code' => 'TYPO-02'])->exists)->toBeTrue();
});

it('still offers options for a property the user can see', function () {
    // The guard must not break the normal case.
    equip($this->mine->id, ['code' => 'ESC-01']);
    Unit::create(['asset_id' => $this->mine->id, 'code' => 'U-1', 'category' => 'retail', 'area_sqm' => 30, 'status' => 'vacant']);

    $all = ensureAllPropertiesAsset();
    $this->actingAs(makeUser('operations', [$this->mine->id]));

    asTenant($all, function () {
        Livewire::test(CreateEquipment::class)
            ->fillForm(['asset_id' => $this->mine->id])
            ->assertFormFieldExists('parent_id', fn ($f) => $f->getOptions() !== [])
            ->assertFormFieldExists('unit_id', fn ($f) => $f->getOptions() !== []);
    });
});
