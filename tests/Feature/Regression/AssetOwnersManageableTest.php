<?php

use App\Filament\Admin\RelationManagers\AssetOwnersRelationManager;
use App\Filament\Admin\Resources\Assets\AssetResource;

/**
 * A real install must be able to record who owns a property.
 *
 * The `asset_owner` pivot had **no UI anywhere** — only `DemoSeeder` ever wrote it. So on a real
 * install there was no way to say that Jawad owns Atriom Walk, or at what share, and two things
 * followed silently:
 *
 *  - **Owner statements had nothing to divide by.** `GenerateOwnerStatementRunService` apportions
 *    a property's net by `ownership_percentage`; no rows means no owner and no statement.
 *  - **An owner who signed in saw an empty dashboard**, because `AssignedAssets` fails closed with
 *    a `[0]` sentinel — the right behaviour, but it makes the symptom look like a permissions
 *    problem rather than missing reference data.
 *
 * An in-code comment made it worse: `PropertyIsolation` described `AssetOwner` as "managed via
 * User/Asset relations", which described a UI that did not exist. A comment asserting a mechanism
 * is how an absence stays invisible.
 */
it('registers the owners relation manager on the Asset resource', function () {
    expect(AssetResource::getRelations())->toContain(AssetOwnersRelationManager::class);
});

it('lets an operator record an owner and their share', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');

    $asset->owners()->attach($owner->id, [
        'ownership_percentage' => 60.00,
        'started_at' => '2026-01-01',
    ]);

    $pivot = $asset->fresh()->owners()->first()->pivot;

    expect((float) $pivot->ownership_percentage)->toBe(60.0)
        ->and($pivot->coversDate('2026-06-01'))->toBeTrue();
});

it('keeps a former owner resolvable after a sale', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');

    // A sale is an END DATE, never a deleted row — the former owner's past statements have to keep
    // resolving, which is why the relation manager warns before a detach.
    $asset->owners()->attach($owner->id, [
        'ownership_percentage' => 100.00,
        'started_at' => '2024-01-01',
        'ended_at' => '2026-03-31',
    ]);

    $pivot = $asset->fresh()->owners()->first()->pivot;

    expect($pivot->coversDate('2026-02-01'))->toBeTrue()
        ->and($pivot->coversDate('2026-06-01'))->toBeFalse();
});

it('gates managing owners on assets.edit, and refuses without it', function () {
    // Deliberately a DIFFERENT gate from AssetStaffRelationManager, which uses roles.edit: staff
    // membership grants panel VISIBILITY (a role-management concern), while ownership decides
    // whose money a statement apportions — authority over the property record itself.
    //
    // Asserted by granting the permission rather than by naming a role, because which roles hold
    // assets.edit is the seeder's business and would make this test a duplicate of the seeder.
    // The real catalogue — `seedRoles()` (used by makeUser) creates ROLES only, so without this
    // `assets.edit` does not exist and the assertion below would pass for the wrong reason.
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

    $viewer = makeUser('viewer');
    expect($viewer->can('assets.edit'))->toBeFalse();

    // RolesPermissionsSeeder bulk-inserts the catalogue, which skips the `saved` hook that would
    // invalidate spatie's cache — the documented hazard of that optimisation.
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $viewer->givePermissionTo('assets.edit');

    expect($viewer->fresh()->can('assets.edit'))->toBeTrue();
});

it('has EN and AR labels for every key the relation manager renders', function () {
    foreach ([
        'admin.sections.asset_owners',
        'admin.sections.asset_owners_detach_warning',
        'admin.fields.owner',
        'admin.fields.ownership_percentage',
        'admin.fields.owned_since',
        'admin.fields.owned_until',
        'admin.fields.owned_until_open',
        'admin.empty.asset_owners.heading',
    ] as $key) {
        expect(__($key, [], 'en'))->not->toBe($key, "missing EN key {$key}")
            ->and(__($key, [], 'ar'))->not->toBe($key, "missing AR key {$key}");
    }
});
