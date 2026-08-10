<?php

use App\Models\Asset;

/**
 * The property's area figures mean something (module 01 close-out).
 *
 * **`assets.total_area_sqm` was write-only.** The form asked an operator for the gross building
 * area and nothing in the application ever read it — the same shape as the inert-settings bug,
 * where a screen accepts a number and quietly discards it. The remedy was not to delete a real
 * property attribute but to make it answer the question it was implicitly asking: how much of this
 * building can actually be let.
 */
it('reports leasable area as a share of the building', function () {
    $asset = makeAsset(['total_area_sqm' => 12000, 'leasable_area_sqm' => 8500]);

    expect($asset->leasableEfficiencyPct())->toBe(70.8);
});

it('falls back to the units’ own area when no leasable area is declared', function () {
    // The same fallback the CAM denominator uses, so the two screens cannot disagree about what
    // "leasable" means.
    $asset = makeAsset(['total_area_sqm' => 1000, 'leasable_area_sqm' => 0]);
    makeUnit($asset, ['code' => 'U-1', 'area_sqm' => 300]);
    makeUnit($asset, ['code' => 'U-2', 'area_sqm' => 400]);

    expect($asset->fresh()->leasableEfficiencyPct())->toBe(70.0);
});

it('says unknown rather than zero when the gross area is not recorded', function () {
    // 0% would read as a building with nothing lettable in it, which is a claim the data does not
    // support — the same distinction the rent roll draws for a unit with no area.
    expect(makeAsset(['total_area_sqm' => 0, 'leasable_area_sqm' => 500])->leasableEfficiencyPct())
        ->toBeNull();
});

it('is not moved by parking, because a bay is not lettable area', function () {
    $asset = makeAsset(['total_area_sqm' => 1000, 'leasable_area_sqm' => 700]);
    $before = $asset->leasableEfficiencyPct();

    \App\Models\RentableItem::create([
        'asset_id' => $asset->id, 'code' => 'P-001', 'monthly_rate' => 900,
    ]);

    expect($asset->fresh()->leasableEfficiencyPct())->toBe($before)->toBe(70.0);
});

it('reports economic occupancy from the same definition the dashboard uses', function () {
    $asset = makeAsset(['total_area_sqm' => 1000, 'leasable_area_sqm' => 800]);
    makeUnit($asset, ['code' => 'O-1', 'area_sqm' => 300, 'status' => 'occupied']);
    makeUnit($asset, ['code' => 'O-2', 'area_sqm' => 100, 'status' => 'vacant']);

    expect($asset->fresh()->areaOccupancyRate())->toBe(75.0)
        ->and($asset->fresh()->occupiedAreaSqm())->toBe(300.0);

    // The dashboard computes the same figure over a WIDER scope through the same definition — the
    // formula was written out by hand in both places before, so the day "occupied" changed they
    // would have disagreed about the mall's headline number with nothing failing.
    expect(\App\Support\Occupancy::forUnits(\App\Models\Unit::where('asset_id', $asset->id))['pct'])
        ->toBe(75.0);
});

it('keeps the 0.0 contract for a property with no units', function () {
    // `AssetOccupancyTest` pins this and callers rely on a float. The "unconfigured, not empty"
    // distinction is made on the SCREEN — the properties table shows "—" rather than a red 0%.
    expect(makeAsset()->areaOccupancyRate())->toBe(0.0)
        ->and(\App\Support\Occupancy::forUnits(\App\Models\Unit::where('asset_id', 0))['pct'])->toBeNull();
});

it('is not moved by parking, which is not a unit', function () {
    $asset = makeAsset();
    makeUnit($asset, ['code' => 'O-1', 'area_sqm' => 100, 'status' => 'occupied']);

    \App\Models\RentableItem::create(['asset_id' => $asset->id, 'code' => 'P-9', 'monthly_rate' => 900]);

    expect($asset->fresh()->areaOccupancyRate())->toBe(100.0);
});

it('reports leasable area and occupancy per floor, without a column for it', function () {
    // Per-floor GLA was flagged as the thing that might justify promoting `Floor` to an entity. It
    // already IS one, and the figure is a sum over the units standing on it — storing it would be a
    // second truth about the same square metres.
    $asset = makeAsset();
    $ground = \App\Models\Floor::create(['asset_id' => $asset->id, 'code' => 'G', 'level' => 0]);
    $first = \App\Models\Floor::create(['asset_id' => $asset->id, 'code' => '1', 'level' => 1]);

    makeUnit($asset, ['code' => 'G-1', 'area_sqm' => 300, 'status' => 'occupied', 'floor_id' => $ground->id]);
    makeUnit($asset, ['code' => 'G-2', 'area_sqm' => 100, 'status' => 'vacant', 'floor_id' => $ground->id]);
    makeUnit($asset, ['code' => '1-1', 'area_sqm' => 200, 'status' => 'occupied', 'floor_id' => $first->id]);

    expect($ground->areaFigures()['total_sqm'])->toBe(400.0)
        ->and($ground->areaFigures()['pct'])->toBe(75.0)
        ->and($first->areaFigures()['pct'])->toBe(100.0);

    // A floor with nothing on it is unknown, not 0% — the same distinction the property draws.
    $empty = \App\Models\Floor::create(['asset_id' => $asset->id, 'code' => 'B1', 'level' => -1]);
    expect($empty->areaFigures()['pct'])->toBeNull();
});
