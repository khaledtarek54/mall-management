<?php

use App\Filament\Admin\Resources\UtilityMeters\Pages\EditUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\RelationManagers\ReadingsRelationManager;
use App\Models\MeterReading;
use App\Models\UtilityMeter;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->meter = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'HW-E-' . uniqid(),
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
});

it('the relation manager renders without error', function () {
    asTenant($this->asset, function () {
        Livewire::test(ReadingsRelationManager::class, [
            'ownerRecord' => $this->meter,
            'pageClass' => EditUtilityMeter::class,
        ])->assertSuccessful();
    });
});

it('saving a reading via the relation manager stores it against the parent meter', function () {
    asTenant($this->asset, function () {
        Livewire::test(ReadingsRelationManager::class, [
            'ownerRecord' => $this->meter,
            'pageClass' => EditUtilityMeter::class,
        ])
            ->callTableAction('create', data: [
                'reading_date' => now()->startOfMonth()->toDateString(),
                'reading_value' => 5000,
                'consumption' => 250,
                'cost' => 750,
            ])
            ->assertHasNoTableActionErrors();
    });

    expect(MeterReading::where('utility_meter_id', $this->meter->id)->count())->toBe(1);
    expect((float) MeterReading::where('utility_meter_id', $this->meter->id)->value('consumption'))->toBe(250.0);
});
