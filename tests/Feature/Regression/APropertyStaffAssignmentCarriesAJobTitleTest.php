<?php

use App\Filament\Admin\RelationManagers\AssetStaffRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Title at this property" wrote nowhere.
 *
 * Reported by the tester: type a title on the Assigned Staff modal, press Save, get "Saved", and the
 * column still reads "—". `2026_07_29_090000_drop_role_from_asset_user` removed the column the field
 * was bound to, and the form field and the table column both survived the drop — so the input
 * rendered, validated, saved and discarded what the operator typed, which is worse than not offering
 * it. The column is back as `title` (never `role` — see the 2026-09-05 migration for why the name
 * carries the whole point).
 *
 * Every case here drives the REAL relation manager. Asserting on `$asset->staff()->attach(...)`
 * would pass with the column absent from `withPivot`, because a direct attach writes columns the
 * pivot never has to expose — and reading it back through the relation is exactly the half that was
 * broken.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
});

function staffRm(\App\Models\Asset $asset): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(AssetStaffRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass' => EditAsset::class,
    ]);
}

it('keeps the title typed when a staff member is attached', function () {
    $asset = makeAsset();
    $staff = makeUser('manager');

    staffRm($asset)
        ->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $staff->getKey(),
            'title' => 'Site Engineer',
        ])
        ->assertHasNoActionErrors();

    expect($asset->fresh()->staff()->first()->pivot->title)->toBe('Site Engineer');
});

it('keeps the title typed when the assignment is edited', function () {
    $asset = makeAsset();
    $staff = makeUser('manager');
    $asset->staff()->attach($staff->id, ['assigned_at' => '2026-09-30', 'title' => 'Site Engineer']);

    staffRm($asset)
        ->callAction(TestAction::make('edit')->table($staff->getKey()), data: [
            'title' => 'Property Manager',
            'assigned_at' => '2026-09-30',
        ])
        ->assertHasNoActionErrors();

    expect($asset->fresh()->staff()->first()->pivot->title)->toBe('Property Manager');
});

it('shows the stored title in the register rather than a dash', function () {
    $asset = makeAsset();
    $staff = makeUser('manager');
    $asset->staff()->attach($staff->id, ['assigned_at' => '2026-09-30', 'title' => 'Leasing Lead']);

    // The READ half. `withPivot` is what makes the column reachable as `pivot.title`; without it
    // the value is in the database and the register still renders the placeholder.
    staffRm($asset)->assertCanSeeTableRecords([$staff])->assertTableColumnStateSet(
        'pivot.title',
        'Leasing Lead',
        $staff,
    );
});

it('leaves the title genuinely optional', function () {
    // An assignment nobody titled is ordinary — the field has never been required, and making it so
    // would refuse every row already on the books.
    $asset = makeAsset();
    $staff = makeUser('manager');

    staffRm($asset)
        ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $staff->getKey()])
        ->assertHasNoActionErrors();

    expect($asset->fresh()->staff()->first()->pivot->title)->toBeNull();
});
