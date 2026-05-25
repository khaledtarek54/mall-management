<?php

beforeEach(function () {
    ensureAllPropertiesAsset();
});

it('canAccessTenant() rejects a soft-deleted asset', function () {
    $asset = makeAsset(['code' => 'XYZ']);
    $user = makeUser('manager', [$asset->id]);

    expect($user->canAccessTenant($asset))->toBeTrue();

    $asset->delete();

    expect($user->canAccessTenant($asset->fresh()))->toBeFalse();
});

it('canAccessTenant() rejects soft-deleted assets even for super_admin', function () {
    $asset = makeAsset(['code' => 'XYZ']);
    $admin = makeUser('super_admin');

    expect($admin->canAccessTenant($asset))->toBeTrue();

    $asset->delete();

    expect($admin->canAccessTenant($asset->fresh()))->toBeFalse();
});

it('canAccessTenant() rejects a non-Asset model', function () {
    $user = makeUser('super_admin');
    $notAnAsset = makeTenant();

    expect($user->canAccessTenant($notAnAsset))->toBeFalse();
});
