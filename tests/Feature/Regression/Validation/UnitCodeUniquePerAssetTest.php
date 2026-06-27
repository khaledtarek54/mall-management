<?php

// Guard: Unit.code must be unique *per asset_id* — not globally.
// Rule lives in app/Filament/Admin/Resources/Units/Schemas/UnitForm.php:
//   code ->unique(ignoreRecord: true, modifyRuleUsing: scope to asset_id)
// Asset A already has a unit "X" → creating another "X" in A must error,
// while creating "X" in a different asset B must succeed.

use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Models\Unit;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->assetA = makeAsset(['code' => 'AAA']);
    $this->assetB = makeAsset(['code' => 'BBB']);

    // Seed an existing unit "X" inside asset A.
    makeUnit($this->assetA, ['code' => 'X']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function fillUnit(array $overrides = []): array
{
    return array_merge([
        'code' => 'X',
        'category' => 'retail',
        'area_sqm' => 100,
        'status' => 'vacant',
    ], $overrides);
}

it('rejects a duplicate unit code within the same asset', function () {
    // Active tenant = asset A → form pins asset_id to A (field is disabled).
    Filament::setTenant($this->assetA);

    Livewire::test(CreateUnit::class)
        ->fillForm(fillUnit(['code' => 'X']))
        ->call('create')
        ->assertHasFormErrors(['code' => 'unique']);

    // No second "X" leaked into asset A.
    expect(Unit::where('asset_id', $this->assetA->id)->where('code', 'X')->count())
        ->toBe(1);
});

it('accepts the same code in a different asset', function () {
    // Active tenant = asset B → asset_id pins to B, where "X" is free.
    Filament::setTenant($this->assetB);

    Livewire::test(CreateUnit::class)
        ->fillForm(fillUnit(['code' => 'X']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Unit::where('asset_id', $this->assetB->id)->where('code', 'X')->exists())
        ->toBeTrue();
});

it('accepts a fresh, non-colliding code within the same asset', function () {
    Filament::setTenant($this->assetA);

    Livewire::test(CreateUnit::class)
        ->fillForm(fillUnit(['code' => 'Y']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Unit::where('asset_id', $this->assetA->id)->where('code', 'Y')->exists())
        ->toBeTrue();
});
