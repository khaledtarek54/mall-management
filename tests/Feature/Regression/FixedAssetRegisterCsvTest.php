<?php

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Module 23 UX pass — the fixed-asset register is exportable to CSV, the schedule that supports the
 * balance sheet's fixed-asset line. Net book value (cost − accumulated depreciation) must match what
 * the table shows, be property-scoped exactly like the derived `accumulated` column, and close with
 * cost / accumulated / NBV totals.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mallA = makeAsset(['code' => 'AAA']);
    $this->mallB = makeAsset(['code' => 'BBB']);
});

function makeRegisterAsset(int $assetId, string $tag, float $cost): FixedAsset
{
    return FixedAsset::create([
        'asset_id' => $assetId,
        'name' => "Asset {$tag}",
        'tag' => $tag,
        'category' => 'equipment',
        'acquisition_date' => now()->subMonths(3)->startOfMonth(),
        'acquisition_cost' => $cost,
        'salvage_value' => 0,
        'useful_life_months' => 10,
        'method' => 'straight_line',
        'funded_from' => 'cash',
        'status' => 'active',
    ]);
}

it('values the register at cost − accumulated depreciation, scoped to the user', function () {
    $asset = makeRegisterAsset($this->mallA->id, 'FA-A', 1000);   // 100/mo over 10 months
    makeRegisterAsset($this->mallB->id, 'FA-B', 5000);            // another mall
    // Post 3 months of depreciation for mall A's asset → accumulated 300, NBV 700.
    // Anchor at startOfMonth BEFORE subtracting: on a 31st, now()->subMonth() overflows to the
    // 1st of the SAME month (June 31 doesn't exist → rolls to July 1), so run(now()) and
    // run(now()->subMonth()) would both hit July and the second is idempotently skipped → only 2
    // months post. startOfMonth()->subMonths(n) is the 1st every time — three distinct months.
    app(DepreciationService::class)->run(now()->startOfMonth(), [$this->mallA->id]);
    app(DepreciationService::class)->run(now()->startOfMonth()->subMonth(), [$this->mallA->id]);
    app(DepreciationService::class)->run(now()->startOfMonth()->subMonths(2), [$this->mallA->id]);

    $this->actingAs(makeUser('accounting', [$this->mallA->id]));

    $csv = FixedAssetResource::registerCsv();
    $row = collect($csv['rows'])->firstWhere(0, 'FA-A');

    // cost 1000, monthly 100, accumulated 300, NBV 700 — and mall B's asset is NOT in scope.
    expect((float) $row[5])->toBe(1000.0)
        ->and((float) $row[6])->toBe(100.0)
        ->and((float) $row[7])->toBe(300.0)
        ->and((float) $row[8])->toBe(700.0)
        ->and(collect($csv['rows'])->firstWhere(0, 'FA-B'))->toBeNull();
});

it('closes the register with cost / accumulated / NBV totals across the portfolio', function () {
    makeRegisterAsset($this->mallA->id, 'FA-A', 1000);
    makeRegisterAsset($this->mallB->id, 'FA-B', 5000);
    app(DepreciationService::class)->run(now(), null); // portfolio-wide: 100 + 500 accumulated

    $this->actingAs(makeUser('super_admin', [$this->mallA->id, $this->mallB->id]));

    $csv = FixedAssetResource::registerCsv();
    $total = collect($csv['rows'])->last();

    // cost 6000, accumulated 600, NBV 5400 in the final total row.
    expect((float) $total[5])->toBe(6000.0)
        ->and((float) $total[7])->toBe(600.0)
        ->and((float) $total[8])->toBe(5400.0);
});
