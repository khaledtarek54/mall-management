<?php

use App\Models\Asset;

beforeEach(function () {
    ensureAllPropertiesAsset();
});

it('super_admin sees every real property as a tenant — never All Properties', function () {
    makeAsset(['code' => 'AAA']);
    makeAsset(['code' => 'BBB']);
    makeAsset(['code' => 'CCC']);

    $admin = makeUser('super_admin');
    $tenants = $admin->getTenants(filament()->getPanel('admin'));

    // Property-first UX: the synthetic "All Properties" pseudo-asset is never
    // offered in the switcher.
    expect($tenants->pluck('code')->all())
        ->toEqualCanonicalizing(['AAA', 'BBB', 'CCC'])
        ->not->toContain('ALL');
});

it('manager sees only assigned properties — never All Properties', function () {
    $a = makeAsset(['code' => 'AAA']);
    makeAsset(['code' => 'BBB']);
    $c = makeAsset(['code' => 'CCC']);

    $user = makeUser('manager', [$a->id, $c->id]);
    $tenants = $user->getTenants(filament()->getPanel('admin'));

    expect($tenants->pluck('code')->all())
        ->toEqualCanonicalizing(['AAA', 'CCC'])
        ->not->toContain('ALL');
});

it('a single-property user sees only their one property', function () {
    $a = makeAsset(['code' => 'AAA']);
    $user = makeUser('manager', [$a->id]);
    $tenants = $user->getTenants(filament()->getPanel('admin'));

    expect($tenants->pluck('code')->all())->toEqual(['AAA']);
});

it('canAccessTenant lets super_admin into every real property', function () {
    $a = makeAsset(['code' => 'AAA']);
    $admin = makeUser('super_admin');

    expect($admin->canAccessTenant($a))->toBeTrue();
});

it('canAccessTenant blocks unassigned properties for non-super-admin', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);

    $user = makeUser('manager', [$a->id]);

    expect($user->canAccessTenant($a))->toBeTrue();
    expect($user->canAccessTenant($b))->toBeFalse();
});

it('canAccessTenant rejects the All Properties pseudo-asset for everyone', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);
    $all = Asset::where('code', 'ALL')->first();

    $superAdmin = makeUser('super_admin');
    $singleProp = makeUser('manager', [$a->id]);
    $multiProp = makeUser('manager', [$a->id, $b->id]);

    // No role, and no matter how many properties, "All Properties" is never a
    // selectable operational tenant — a crafted /admin/ALL/... URL 403s.
    expect($superAdmin->canAccessTenant($all))->toBeFalse();
    expect($singleProp->canAccessTenant($all))->toBeFalse();
    expect($multiProp->canAccessTenant($all))->toBeFalse();
});
