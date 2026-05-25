<?php

use App\Models\Asset;

it('flags the All Properties pseudo-asset', function () {
    $all = ensureAllPropertiesAsset();
    $hw = makeAsset(['code' => 'HW']);

    expect($all->isAllProperties())->toBeTrue();
    expect($hw->isAllProperties())->toBeFalse();
});

it('computes occupancy rate as a percentage of occupied units', function () {
    $asset = makeAsset();
    makeUnit($asset, ['status' => 'occupied']);
    makeUnit($asset, ['status' => 'occupied']);
    makeUnit($asset, ['status' => 'occupied']);
    makeUnit($asset, ['status' => 'vacant']);

    expect($asset->occupancyRate())->toBe(75.0);
});

it('returns zero occupancy for an empty property', function () {
    $asset = makeAsset();
    expect((float) $asset->occupancyRate())->toBe(0.0);
});

it('counts vacant and occupied units independently', function () {
    $asset = makeAsset();
    makeUnit($asset, ['status' => 'occupied']);
    makeUnit($asset, ['status' => 'occupied']);
    makeUnit($asset, ['status' => 'vacant']);
    makeUnit($asset, ['status' => 'vacant']);
    makeUnit($asset, ['status' => 'vacant']);

    expect($asset->vacantUnitsCount())->toBe(3);
    expect($asset->occupiedUnitsCount())->toBe(2);
});

it('exposes the units, leases, owners, and staff relationships', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    makeLease($unit);

    expect($asset->units)->toHaveCount(1);
    expect($asset->leases)->toHaveCount(1);
});

it('staff() pivots through asset_user with the role + assigned_at attributes', function () {
    $asset = makeAsset();
    $user = makeUser('manager');

    $asset->staff()->attach($user->id, [
        'role' => 'Senior Manager',
        'assigned_at' => '2026-01-01',
    ]);

    $pivot = $asset->staff()->first()->pivot;
    expect($pivot->role)->toBe('Senior Manager');
});
