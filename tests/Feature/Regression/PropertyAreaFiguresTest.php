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
