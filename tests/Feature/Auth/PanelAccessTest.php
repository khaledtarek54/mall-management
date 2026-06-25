<?php

use App\Models\Asset;
use Filament\Facades\Filament;

beforeEach(function () {
    ensureAllPropertiesAsset();
});

it('lets every non-owner role into the admin panel', function () {
    $adminPanel = Filament::getPanel('admin');

    foreach (['super_admin', 'manager', 'leasing', 'operations', 'viewer'] as $role) {
        $user = makeUser($role);
        expect($user->canAccessPanel($adminPanel))->toBeTrue("Role {$role} should access /admin");
    }
});

it('lets owners into the admin panel (owners are RBAC users; no separate portal)', function () {
    $owner = makeUser('owner');
    expect($owner->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('lets only owners into the owner panel', function () {
    $ownerPanel = Filament::getPanel('owner');

    $owner = makeUser('owner');
    $manager = makeUser('manager');

    expect($owner->canAccessPanel($ownerPanel))->toBeTrue();
    expect($manager->canAccessPanel($ownerPanel))->toBeFalse();
});

it('non-super-admin user gets restricted to assigned properties via AssignedAssets', function () {
    $a = makeAsset(['code' => 'AAA']);
    makeAsset(['code' => 'BBB']);

    $user = makeUser('manager', [$a->id]);
    $this->actingAs($user);

    expect(\App\Support\AssignedAssets::idsForCurrentUser())->toEqual([$a->id]);
    expect(\App\Support\AssignedAssets::isRestricted($user))->toBeTrue();
});

it('super_admin bypasses property scoping', function () {
    makeAsset(['code' => 'AAA']);
    $admin = makeUser('super_admin');
    $this->actingAs($admin);

    expect(\App\Support\AssignedAssets::idsForCurrentUser())->toBeNull();
    expect(\App\Support\AssignedAssets::isRestricted($admin))->toBeFalse();
});
