<?php

use App\Filament\Admin\RelationManagers\AssetActivitiesRelationManager;
use App\Filament\Admin\RelationManagers\AssetAreasRelationManager;
use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Area;
use App\Models\Asset;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * A property is divided into zones from the property's own page.
 *
 * Asked for by the tester: *"Add a Zones setting in Property edit/create to divide the property into
 * zones."* Zones ALREADY existed — `Area` (module 30) has carried `asset_id`, a per-property unique
 * code and a set of supervisors since it shipped — and were reachable only from their own register
 * under Setup. Nothing new is invented here; what was missing is the door, on the record the zones
 * belong to.
 *
 * It had also already been CLAIMED: `AssetFloorsRelationManager`'s docblock stated in writing that
 * *"units and zones are already managed this way"* — a comment describing a screen nobody had built,
 * which is how an absence stays invisible until someone goes looking for the feature.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
});

function propertyZones(Asset $asset): Testable
{
    return Livewire::test(AssetAreasRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass' => EditAsset::class,
    ]);
}

it('offers the Zones tab on the property', function () {
    expect(AssetResource::getRelations())->toContain(AssetAreasRelationManager::class);
});

it('divides the property into zones from its own page', function () {
    propertyZones($this->asset)
        ->callAction(TestAction::make('create')->table(), data: [
            'code' => 'FC',
            'name' => 'Food Court',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $zone = $this->asset->fresh()->areas()->sole();

    expect($zone->code)->toBe('FC')
        ->and($zone->name)->toBe('Food Court')
        // Stamped from the property whose page it was created on — never from the payload.
        ->and($zone->asset_id)->toBe($this->asset->id);
});

it('lets two malls each have a food court, and refuses two in one mall', function () {
    // A zone's code is unique PER PROPERTY (`areas_asset_code_unique`), and the rule is keyed on the
    // owner record rather than on anything the client sent — so it cannot be used as an existence
    // oracle over another property's codes either.
    $other = makeAsset();
    Area::create(['asset_id' => $other->id, 'code' => 'FC', 'name' => 'Food Court', 'is_active' => true]);

    propertyZones($this->asset)
        ->callAction(TestAction::make('create')->table(), data: ['code' => 'FC', 'name' => 'Food Court'])
        ->assertHasNoActionErrors();

    propertyZones($this->asset->fresh())
        ->callAction(TestAction::make('create')->table(), data: ['code' => 'FC', 'name' => 'Duplicate'])
        ->assertHasActionErrors(['code']);

    expect($this->asset->fresh()->areas()->count())->toBe(1);
});

it('shows only this property s zones', function () {
    $other = makeAsset();
    $mine = Area::create(['asset_id' => $this->asset->id, 'code' => 'FC', 'name' => 'Food Court']);
    $theirs = Area::create(['asset_id' => $other->id, 'code' => 'RP', 'name' => 'Roof Plant']);

    propertyZones($this->asset)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('counts the units standing in each zone', function () {
    // The other half of what a zone is FOR: `units.area_id`. A zone with no units is usually one
    // somebody set up and never assigned, which is worth seeing at a glance.
    $zone = Area::create(['asset_id' => $this->asset->id, 'code' => 'FC', 'name' => 'Food Court']);
    makeUnit($this->asset, ['code' => 'A-01', 'area_id' => $zone->id]);
    makeUnit($this->asset, ['code' => 'A-02', 'area_id' => $zone->id]);
    makeUnit($this->asset, ['code' => 'B-01']);

    expect($zone->fresh()->units()->count())->toBe(2);

    propertyZones($this->asset)->assertTableColumnStateSet('units_count', 2, $zone);
});

it('puts a zone s changes on the property s activity log', function () {
    // A zone is part of the property's spatial make-up, so it belongs beside units, floors and
    // parking on the property's own trail rather than only on its own record.
    $zone = Area::create(['asset_id' => $this->asset->id, 'code' => 'FC', 'name' => 'Food Court']);

    $rows = Activity::where('subject_type', $zone->getMorphClass())
        ->where('subject_id', $zone->id)
        ->get();

    expect($rows)->not->toBeEmpty();

    Livewire::test(AssetActivitiesRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ])->assertCanSeeTableRecords($rows);
});

it('gates creating a zone on the right to edit the property', function () {
    // Same gate as Floors and Units: dividing up a mall is property setup, not a separate right.
    $this->actingAs(makeUser('viewer'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    propertyZones($this->asset)->assertActionHidden(TestAction::make('create')->table());
});
