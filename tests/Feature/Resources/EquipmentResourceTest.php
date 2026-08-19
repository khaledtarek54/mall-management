<?php

use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Admin\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Admin\Resources\Equipment\Pages\ListEquipment;
use App\Models\Equipment;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'EQR']);
});

function equipmentFor(int $assetId, array $attrs = []): Equipment
{
    return Equipment::create(array_merge([
        'asset_id' => $assetId,
        'code' => 'ESC-01',
        'name_en' => 'Main escalator',
        'name_ar' => 'السلم الكهربائي',
        'trade_id' => tradeId('elevator'),
    ], $attrs));
}

/* ---- render with rows: an empty table hides $state-closure bugs --------- */

it('renders the register with a parent and a sub-code row', function () {
    $parent = equipmentFor($this->asset->id, ['code' => 'ESC-01']);
    equipmentFor($this->asset->id, ['code' => 'ESC-01-MOT', 'parent_id' => $parent->id, 'name_en' => 'Motor']);
    // A row with every optional column empty — the closures must survive nulls.
    equipmentFor($this->asset->id, ['code' => 'PMP-01', 'category' => null, 'name_en' => 'Pump', 'unit_id' => null]);

    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(ListEquipment::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Equipment::all());
    });
});

it('renders the form for a new record', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(CreateEquipment::class)->assertSuccessful();
    });
});

/* ---- create / edit ------------------------------------------------------ */

it('creates equipment through the form', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'asset_id' => $this->asset->id,
                'code' => 'CH-01',
                'name_en' => 'Chiller',
                'name_ar' => 'مبرد',
                'trade_id' => tradeId('hvac'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    expect(Equipment::where('code', 'CH-01')->exists())->toBeTrue();
});

it('rejects a duplicate code within the same property', function () {
    equipmentFor($this->asset->id, ['code' => 'CH-01']);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'asset_id' => $this->asset->id,
                'code' => 'CH-01',
                'name_en' => 'Another chiller',
                'name_ar' => 'مبرد آخر',
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    });
});

it('requires code and both names', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::test(CreateEquipment::class)
            ->fillForm(['asset_id' => $this->asset->id, 'code' => '', 'name_en' => '', 'name_ar' => ''])
            ->call('create')
            ->assertHasFormErrors(['code', 'name_en', 'name_ar']);
    });
});

/* ---- property isolation on write ---------------------------------------- */

it('refuses to create equipment in a property outside the user\'s set', function () {
    $all = ensureAllPropertiesAsset();
    $other = makeAsset(['code' => 'EQX']);
    $this->actingAs(makeUser('operations', [$this->asset->id])); // not assigned to $other

    asTenant($all, function () use ($other) {
        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'asset_id' => $other->id,
                'code' => 'SNEAK-01',
                'name_en' => 'Sneaky',
                'name_ar' => 'متسلل',
            ])
            ->call('create')
            ->assertHasFormErrors(['asset_id']);
    });

    expect(Equipment::where('code', 'SNEAK-01')->exists())->toBeFalse();
});

it('refuses to move equipment to a property outside the user\'s set on edit', function () {
    // Filament stamps asset_id on create only, never on update — the edit page must guard.
    $all = ensureAllPropertiesAsset();
    $other = makeAsset(['code' => 'EQY']);
    $e = equipmentFor($this->asset->id, ['code' => 'MOVE-01']);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($all, function () use ($e, $other) {
        try {
            Livewire::test(EditEquipment::class, ['record' => $e->id])
                ->fillForm(['asset_id' => $other->id])
                ->call('save');
        } catch (Throwable $ex) {
            // abort(403) may surface as an exception depending on the Livewire path.
        }
    });

    expect((int) $e->fresh()->asset_id)->toBe($this->asset->id);
});

/* ---- RBAC + module flag ------------------------------------------------- */

it('hides the register from a role without facility.view', function () {
    $this->actingAs(makeUser('leasing', [$this->asset->id]));

    expect(EquipmentResource::canViewAny())->toBeFalse();
});

it('hides the register when the facility module is off', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));
    expect(EquipmentResource::canViewAny())->toBeTrue();

    $settings = app(ModulesSettings::class);
    $settings->facility = false;
    $settings->save();

    expect(EquipmentResource::canViewAny())->toBeFalse();
});
