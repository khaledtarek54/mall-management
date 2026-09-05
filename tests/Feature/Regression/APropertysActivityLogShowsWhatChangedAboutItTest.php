<?php

use App\Filament\Admin\RelationManagers\AssetActivitiesRelationManager;
use App\Filament\Admin\RelationManagers\AssetOwnersRelationManager;
use App\Filament\Admin\RelationManagers\AssetStaffRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use App\Models\Floor;
use App\Models\RentableItem;
use App\Models\Unit;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Adding a staff member, owner, unit, parking, or floor to a property doesn't show up in the
 * property's Activity Log."
 *
 * ONE symptom, THREE causes, and only the third was the tab itself:
 *
 *  1. **Staff and owners logged nothing at all, anywhere.** `attach()` writes through the query
 *     builder and fires no model event, so no observer ever saw a roster change — and attaching a
 *     staff member GRANTS them access to the property, which is the one class of change an audit
 *     trail exists for.
 *  2. **`Unit` was audited nowhere in the system.** Creating, re-homing or re-categorising a shop
 *     recorded nothing, on the record every lease and every CAM apportionment hangs off.
 *  3. **The tab only ever read the Asset ROW's own activity.** Floors and rentable items were
 *     already audited; they were simply filed under their own subject and the tab never asked.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
});

function assetActivity(Asset $asset): Testable
{
    return Livewire::test(AssetActivitiesRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass' => EditAsset::class,
    ]);
}

it('records a unit being created, and shows it on the property', function () {
    $unit = makeUnit($this->asset, ['code' => 'A-01']);

    expect(Activity::where('subject_type', $unit->getMorphClass())->where('subject_id', $unit->id)->exists())
        ->toBeTrue();

    assetActivity($this->asset)->assertCanSeeTableRecords(
        Activity::where('subject_type', $unit->getMorphClass())->where('subject_id', $unit->id)->get()
    );
});

it('shows a floor and a rentable item on the property too', function () {
    $floor = Floor::create(['asset_id' => $this->asset->id, 'code' => 'G', 'name' => 'Ground', 'level' => 0]);
    $bay = RentableItem::create([
        'asset_id' => $this->asset->id,
        'code' => 'P-001',
        'name' => 'Bay P-001',
        'type' => 'parking',
        'status' => RentableItem::STATUS_AVAILABLE,
        'monthly_rate' => 500,
    ]);

    $rows = Activity::query()
        ->where(fn ($q) => $q->where('subject_type', $floor->getMorphClass())->where('subject_id', $floor->id))
        ->orWhere(fn ($q) => $q->where('subject_type', $bay->getMorphClass())->where('subject_id', $bay->id))
        ->get();

    expect($rows)->toHaveCount(2);

    assetActivity($this->asset)->assertCanSeeTableRecords($rows);
});

it('records a staff member being attached and detached', function () {
    $staff = makeUser('manager');

    $rm = fn () => Livewire::test(AssetStaffRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ]);

    $rm()->callAction(TestAction::make('attach')->table(), data: ['recordId' => $staff->getKey()])
        ->assertHasNoActionErrors();

    $attached = Activity::where('log_name', 'asset')->where('event', 'attached')->get();
    expect($attached)->toHaveCount(1)
        ->and($attached->first()->subject_id)->toBe($this->asset->id)
        // The NAME travels as data; the wording is resolved in the reader's language at read time.
        ->and($attached->first()->properties['attributes']['staff'])->toBe($staff->name);

    $rm()->callAction(TestAction::make('detach')->table($staff->getKey()))
        ->assertHasNoActionErrors();

    expect(Activity::where('log_name', 'asset')->where('event', 'detached')->count())->toBe(1);
});

it('records an owner being attached', function () {
    $owner = makeUser('owner');

    Livewire::test(AssetOwnersRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ])->callAction(TestAction::make('attach')->table(), data: [
        'recordId' => $owner->getKey(),
        'ownership_percentage' => 100,
    ])->assertHasNoActionErrors();

    $row = Activity::where('log_name', 'asset')->where('event', 'attached')->sole();

    expect($row->properties['attributes']['owner'])->toBe($owner->name)
        ->and($row->subject_id)->toBe($this->asset->id);
});

it('does not show another property\'s children', function () {
    // The clause that matters. Composing an `orWhere` onto the relation's already-applied
    // `subject = this asset` constraint binds AND-before-OR, and the child branch then escapes the
    // property scope entirely — every unit in the portfolio on every property's tab.
    $other = makeAsset();
    $mine = makeUnit($this->asset, ['code' => 'A-01']);
    $theirs = makeUnit($other, ['code' => 'B-01']);

    $rows = assetActivity($this->asset)->instance()->getTableQuery()->get();
    $subjects = $rows->where('subject_type', $mine->getMorphClass())->pluck('subject_id');

    expect($subjects)->toContain($mine->id)
        ->and($subjects)->not->toContain($theirs->id);
});

it('still shows the property\'s own changes', function () {
    // The control: widening must not have replaced what the tab already did.
    $this->asset->update(['name' => 'Renamed Mall']);

    $own = Activity::where('subject_type', $this->asset->getMorphClass())
        ->where('subject_id', $this->asset->id)
        ->get();

    expect($own)->not->toBeEmpty();

    assetActivity($this->asset)->assertCanSeeTableRecords($own);
});
