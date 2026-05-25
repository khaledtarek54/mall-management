<?php

use App\Models\Asset;

beforeEach(function () {
    ensureAllPropertiesAsset();
});

it('super_admin sees every property as a tenant', function () {
    makeAsset(['code' => 'AAA']);
    makeAsset(['code' => 'BBB']);
    makeAsset(['code' => 'CCC']);

    $admin = makeUser('super_admin');
    $tenants = $admin->getTenants(filament()->getPanel('admin'));

    expect($tenants->pluck('code')->all())
        ->toEqualCanonicalizing(['ALL', 'AAA', 'BBB', 'CCC']);
});

it('manager sees only assigned properties', function () {
    $a = makeAsset(['code' => 'AAA']);
    makeAsset(['code' => 'BBB']);
    $c = makeAsset(['code' => 'CCC']);

    $user = makeUser('manager', [$a->id, $c->id]);
    $tenants = $user->getTenants(filament()->getPanel('admin'));

    expect($tenants->pluck('code')->all())
        ->toEqualCanonicalizing(['ALL', 'AAA', 'CCC']);
});

it('omits the All Properties option for users with a single property', function () {
    $a = makeAsset(['code' => 'AAA']);
    $user = makeUser('manager', [$a->id]);
    $tenants = $user->getTenants(filament()->getPanel('admin'));

    expect($tenants->pluck('code')->all())->toEqual(['AAA']);
});

it('canAccessTenant lets super_admin into every property', function () {
    $a = makeAsset(['code' => 'AAA']);
    $admin = makeUser('super_admin');

    expect($admin->canAccessTenant($a))->toBeTrue();
    expect($admin->canAccessTenant(Asset::where('code', 'ALL')->first()))->toBeTrue();
});

it('canAccessTenant blocks unassigned properties for non-super-admin', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);

    $user = makeUser('manager', [$a->id]);

    expect($user->canAccessTenant($a))->toBeTrue();
    expect($user->canAccessTenant($b))->toBeFalse();
});

it('canAccessTenant only lets the user into All Properties when they have multiple', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);
    $all = Asset::where('code', 'ALL')->first();

    $singleProp = makeUser('manager', [$a->id]);
    $multiProp = makeUser('manager', [$a->id, $b->id]);

    expect($singleProp->canAccessTenant($all))->toBeFalse();
    expect($multiProp->canAccessTenant($all))->toBeTrue();
});
