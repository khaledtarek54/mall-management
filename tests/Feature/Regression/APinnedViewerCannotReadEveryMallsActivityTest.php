<?php

/**
 * The activity feed is portfolio-wide, so only a portfolio-wide account may read it.
 *
 * `ActivityLog::canAccess()` gated on `activity_log.view` alone, under a docblock whose own
 * reasoning explains why that is not enough: *"the activity feed spans every property and has no
 * asset_id, so it cannot be cleanly scoped… a property-restricted user would otherwise read other
 * properties' financial and tenant activity — which is why the grant itself stops at the
 * full-portfolio roles."*
 *
 * **That premise was false.** `viewer` and `manager` both hold the key, and both can be pinned to a
 * single mall through the ordinary property-assignment field on the user form —
 * `AssignedAssets::idsFor()` restricts anyone carrying an assignment, whatever their role. Measured
 * before the fix: a `viewer` assigned to mall AA opened the page and read a tenant rename that
 * happened in mall BB. The same leak `RolesPermissionsSeeder::MALL_ADMIN_WITHHELD` exists to
 * prevent, reached through a different door.
 *
 * The gate now asks the SCOPE, not the role list, so a future grant cannot reopen it.
 */

use App\Filament\Admin\Pages\ActivityLog;
use App\Support\AssignedAssets;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->mine = makeAsset(['code' => 'AA']);
    $this->theirs = makeAsset(['code' => 'BB']);

    // Something to leak: a tenant renamed while nobody was watching the other mall.
    $this->actingAs(makeUser('super_admin'));
    $tenant = makeTenant(['name' => 'Other Mall Tenant']);
    $tenant->update(['name' => 'Renamed In The Other Mall']);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses the feed to an account pinned to one mall', function (string $role) {
    $this->flushSession();
    $user = makeUser($role, [$this->mine->id]);
    $this->actingAs($user);

    // The premise: this account genuinely holds the right, and is genuinely pinned. Without both,
    // the refusal below would be proving something else.
    expect($user->can('activity_log.view'))->toBeTrue()
        ->and(AssignedAssets::idsFor($user))->toBe([$this->mine->id]);

    asTenant($this->mine, fn () => expect(ActivityLog::canAccess())->toBeFalse());

    $this->get(ActivityLog::getUrl(tenant: $this->mine))
        ->assertForbidden()
        ->assertDontSee('Renamed In The Other Mall');
})->with(['viewer', 'manager']);

it('still gives it to an auditor who holds EVERY mall', function (string $role) {
    // The control, and the thing that shaped the rule. "Portfolio-wide" cannot mean "has no
    // assignment": an unassigned account cannot enter a mall's URL at all (`canAccessTenant()`
    // refuses and the panel 404s), so that reading collapses to super-admin-only while refusing an
    // auditor who legitimately holds everything. Holding every mall is the honest test — the feed
    // shows them nothing they could not already reach.
    $this->flushSession();
    $user = makeUser($role, [$this->mine->id, $this->theirs->id]);
    $this->actingAs($user);

    expect(AssignedAssets::idsFor($user))->toEqualCanonicalizing([$this->mine->id, $this->theirs->id]);

    asTenant($this->mine, fn () => expect(ActivityLog::canAccess())->toBeTrue());

    $this->get(ActivityLog::getUrl(tenant: $this->mine))
        ->assertOk()
        ->assertSee('Renamed In The Other Mall');
})->with(['viewer', 'manager']);

it('takes it away again the day a mall they do not hold is registered', function () {
    // The rule degrades in the right direction. An auditor holding everything today is holding a
    // SUBSET tomorrow, and that is the moment the feed starts spanning something they are not
    // entitled to.
    $this->flushSession();
    $auditor = makeUser('viewer', [$this->mine->id, $this->theirs->id]);
    $this->actingAs($auditor);

    asTenant($this->mine, fn () => expect(ActivityLog::canAccess())->toBeTrue());

    makeAsset(['code' => 'CC']);

    asTenant($this->mine, fn () => expect(ActivityLog::canAccess())->toBeFalse());
});

it('refuses a pinned super admin nothing, because a super admin is never pinned', function () {
    // `AssignedAssets::idsFor()` returns null for a super admin before it looks at assignments, so
    // assigning one a property does not narrow them — and must not cost them the feed either.
    $this->flushSession();
    $admin = makeUser('super_admin', [$this->mine->id]);
    $this->actingAs($admin);

    expect(AssignedAssets::idsFor($admin))->toBeNull();

    $this->get(ActivityLog::getUrl(tenant: $this->mine))
        ->assertOk()
        ->assertSee('Renamed In The Other Mall');
});
