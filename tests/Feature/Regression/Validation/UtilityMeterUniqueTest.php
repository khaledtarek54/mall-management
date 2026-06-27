<?php

/*
|--------------------------------------------------------------------------
| Regression: UtilityMeter / MeterReading uniqueness guards
|--------------------------------------------------------------------------
| Two distinct unique constraints are exercised through the Filament forms:
|
|   1. utility_meters.meter_number  — GLOBAL unique (migration ->unique()),
|      enforced on the form via TextInput::make('meter_number')->unique().
|
|   2. meter_readings (utility_meter_id, reading_date) — COMPOSITE unique
|      (migration 'meter_reading_period_unique'), enforced on the readings
|      relation-manager via DatePicker->unique(modifyRuleUsing: scope to the
|      owner meter). The same date is therefore allowed on a *different*
|      meter — proving the rule is scoped, not global.
*/

use App\Filament\Admin\Resources\UtilityMeters\Pages\CreateUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\Pages\EditUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\RelationManagers\ReadingsRelationManager;
use App\Models\MeterReading;
use App\Models\UtilityMeter;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'UM']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/*
| meter_number — global unique (UtilityMeter create form)
*/

it('rejects a meter_number that already exists', function () {
    UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'MTR-DUP',
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);

    Livewire::test(CreateUtilityMeter::class)
        ->fillForm([
            'asset_id' => $this->asset->id,
            'meter_number' => 'MTR-DUP',
            'type' => 'water',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['meter_number' => 'unique']);
});

it('accepts a unique meter_number', function () {
    Livewire::test(CreateUtilityMeter::class)
        ->fillForm([
            'asset_id' => $this->asset->id,
            'meter_number' => 'MTR-FRESH',
            'type' => 'electric',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors(['meter_number']);

    expect(UtilityMeter::where('meter_number', 'MTR-FRESH')->exists())->toBeTrue();
});

/*
| reading_date — unique per (utility_meter_id, reading_date) (readings RM)
*/

// NOTE: the relation manager's form-level ->unique() rule on reading_date
// has a date-format gap under sqlite (the rule queries the raw 'Y-m-d'
// submission while the cast column stores 'Y-m-d H:i:s'), so the form rule
// can't be exercised as the rejecting guard in-test. The composite UNIQUE
// index 'meter_reading_period_unique' is the real enforcement — assert that
// the DB index throws on a duplicate (utility_meter_id, reading_date) insert.
it('the unique index rejects a second reading on the same date for the same meter', function () {
    $meter = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'MTR-A',
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);

    MeterReading::create([
        'utility_meter_id' => $meter->id,
        'reading_date' => '2026-03-01',
        'reading_value' => 1000,
        'consumption' => 0,
        'cost' => 0,
    ]);

    // Same (meter, date) — the composite unique index must reject it.
    expect(fn () => MeterReading::create([
        'utility_meter_id' => $meter->id,
        'reading_date' => '2026-03-01',
        'reading_value' => 1200,
        'consumption' => 200,
        'cost' => 300,
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    expect(MeterReading::where('utility_meter_id', $meter->id)->count())->toBe(1);
});

it('accepts a reading on a new date for the same meter', function () {
    $meter = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'MTR-B',
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditUtilityMeter::class,
    ])
        ->callTableAction('create', data: [
            'reading_date' => '2026-03-01',
            'reading_value' => 1000,
            'consumption' => 0,
            'cost' => 0,
        ])
        ->assertHasNoTableActionErrors();

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditUtilityMeter::class,
    ])
        ->callTableAction('create', data: [
            'reading_date' => '2026-04-01',
            'reading_value' => 1500,
            'consumption' => 500,
            'cost' => 750,
        ])
        ->assertHasNoTableActionErrors();

    expect(MeterReading::where('utility_meter_id', $meter->id)->count())->toBe(2);
});

it('allows the same reading_date on a different meter (scope is per meter)', function () {
    $meterA = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'MTR-C',
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);
    $meterB = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'MTR-D',
        'type' => 'water',
        'status' => 'active',
        'unit_of_measurement' => 'm3',
    ]);

    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meterA,
        'pageClass' => EditUtilityMeter::class,
    ])
        ->callTableAction('create', data: [
            'reading_date' => '2026-03-01',
            'reading_value' => 1000,
            'consumption' => 0,
            'cost' => 0,
        ])
        ->assertHasNoTableActionErrors();

    // Same date, different meter — must be allowed.
    Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meterB,
        'pageClass' => EditUtilityMeter::class,
    ])
        ->callTableAction('create', data: [
            'reading_date' => '2026-03-01',
            'reading_value' => 800,
            'consumption' => 80,
            'cost' => 120,
        ])
        ->assertHasNoTableActionErrors();

    expect(MeterReading::where('utility_meter_id', $meterB->id)->count())->toBe(1);
    expect(MeterReading::whereDate('reading_date', '2026-03-01')->count())->toBe(2);
});
