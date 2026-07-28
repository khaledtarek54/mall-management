<?php

/**
 * Archiving a property must not un-scope the staff assigned to it.
 *
 * AssignedAssets distinguishes two cases when a user currently holds no
 * properties:
 *
 *   - NEVER scoped (no assignment or ownership ever) → null = unrestricted.
 *     This is deliberate single-mall back-compat.
 *   - scope has LAPSED (ex-staff, former owner) → the sentinel [0] = sees
 *     nothing. Fail-closed.
 *
 * The "ever scoped?" probe used `assignedAssets()` / `ownedAssets()`, which are
 * relations to a SOFT-DELETING model. Soft-delete a property and those return
 * nothing — so a user assigned to exactly that property fell into the NEVER
 * branch and became unrestricted, gaining read access to every OTHER property
 * in the portfolio. Archiving a mall is an ordinary super_admin action, which
 * makes this reachable rather than theoretical.
 */

use App\Support\AssignedAssets;
use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('keeps staff fail-closed when their only property is archived', function () {
    $mine = makeAsset(['code' => 'HW']);
    $other = makeAsset(['code' => 'PA']);

    $user = makeUser('operations', [$mine->id]);

    // Baseline: scoped to their one property.
    expect(AssignedAssets::idsFor($user))->toEqual([$mine->id]);

    $mine->delete();

    $ids = AssignedAssets::idsFor($user->fresh());

    // MUST NOT be null — null means "no scoping applies", i.e. every property.
    expect($ids)->not->toBeNull(
        'Archiving the only assigned property un-scoped the user to the whole portfolio.'
    );

    // The fail-closed sentinel: truthy (so ->when() still applies the filter)
    // but matching no real asset id.
    expect($ids)->toEqual([0])
        ->and($ids)->not->toContain($other->id);
});

it('keeps a former owner fail-closed when the owned property is archived', function () {
    $owned = makeAsset(['code' => 'OW']);
    $other = makeAsset(['code' => 'OT']);

    $user = makeUser('owner');
    $user->ownedAssets()->attach($owned->id, [
        'ownership_percentage' => 100,
        'started_at' => now()->subYear()->toDateString(),
    ]);

    expect(AssignedAssets::idsFor($user->fresh()))->toEqual([$owned->id]);

    $owned->delete();

    expect(AssignedAssets::idsFor($user->fresh()))->toEqual([0])
        ->and(AssignedAssets::idsFor($user->fresh()))->not->toContain($other->id);
});

it('propagates the lapsed scope through the whole scoping layer', function () {
    // AssignedAssets is the ONE place in the codebase that decides
    // "restricted vs unrestricted" — TenantScope::visibleAssetIds() and
    // reportAssetIds() both delegate to it, and every widget, report and
    // resource query funnels through those. So the fix is only worth anything
    // if it survives that hop; assert it rather than assume it.
    $mine = makeAsset(['code' => 'HW']);
    $other = makeAsset(['code' => 'PA']);

    $user = makeUser('operations', [$mine->id]);
    $mine->delete();

    $this->actingAs($user->fresh());

    expect(TenantScope::visibleAssetIds())->toEqual([0])
        ->and(TenantScope::visibleAssetIds())->not->toContain($other->id);

    // A ledger report with no explicit pick must land on the same sentinel,
    // not on "all properties".
    expect(TenantScope::reportAssetIds(null))->toEqual([0]);

    // …and a hand-typed property id they never had access to is not honoured.
    expect(TenantScope::reportAssetIds($other->id))->toEqual([0]);
});

it('still treats a genuinely unassigned user as unrestricted', function () {
    // The back-compat path this fix must not break: a single-mall deployment
    // where nobody has explicit assignments.
    makeAsset(['code' => 'ONLY']);

    expect(AssignedAssets::idsFor(makeUser('manager')))->toBeNull();
});
