<?php

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

it('computes economic (area) occupancy weighted by leasable area, not headcount', function () {
    $asset = makeAsset();
    // One large occupied anchor next to three small vacant kiosks: 3 of 4 units are empty,
    // yet the anchor holds most of the leasable area — so economic occupancy is HIGH while
    // unit-count occupancy is LOW. That divergence is the whole point of the metric.
    makeUnit($asset, ['status' => 'occupied', 'area_sqm' => 2000]);
    makeUnit($asset, ['status' => 'vacant', 'area_sqm' => 200]);
    makeUnit($asset, ['status' => 'vacant', 'area_sqm' => 200]);
    makeUnit($asset, ['status' => 'vacant', 'area_sqm' => 200]);

    expect($asset->occupancyRate())->toBe(25.0);                 // 1 of 4 units
    expect($asset->areaOccupancyRate())->toBe(76.9);            // 2000 / 2600 m²
    expect($asset->occupiedAreaSqm())->toBe(2000.0);
    expect($asset->totalUnitAreaSqm())->toBe(2600.0);
});

it('returns zero economic occupancy for a property with no units (no divide-by-zero)', function () {
    $asset = makeAsset();
    expect($asset->areaOccupancyRate())->toBe(0.0);
    expect($asset->totalUnitAreaSqm())->toBe(0.0);
});

it('returns zero economic occupancy when every unit has zero recorded area', function () {
    $asset = makeAsset();
    makeUnit($asset, ['status' => 'occupied', 'area_sqm' => 0]);
    makeUnit($asset, ['status' => 'vacant', 'area_sqm' => 0]);

    // Denominator is zero → guard returns 0%, never NaN, even though a unit is occupied.
    expect($asset->areaOccupancyRate())->toBe(0.0);
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

it('staff() pivots through asset_user carrying assignment TENURE', function () {
    // `role` used to be asserted here and was dropped (2026_07_29_090000): nothing
    // read it and its three writers each meant something different. What the pivot
    // carries now is tenure — and that IS read, by AssignedAssets, which decides
    // whether a user is scoped to a property or sees nothing.
    $asset = makeAsset();
    $user = makeUser('manager');

    $asset->staff()->attach($user->id, [
        'assigned_at' => '2026-01-01',
        'ended_at' => null,
        'notes' => 'Covering the north wing',
    ]);

    $pivot = $asset->staff()->first()->pivot;

    expect($pivot->assigned_at)->not->toBeNull()
        ->and($pivot->ended_at)->toBeNull()
        ->and($pivot->notes)->toBe('Covering the north wing');
});
