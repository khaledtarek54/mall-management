<?php

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * SW-193 — "Fully depreciated" finds the assets it exists to find.
 *
 * An asset is fully depreciated when accumulated has reached the DEPRECIABLE BASE — cost less
 * salvage — and `accumulated` is `opening_accumulated_depreciation` plus every entry since, the one
 * definition `FixedAsset::accumulatedDepreciation()` states.
 *
 * The filter compared the GROSS cost against the entries alone, so it missed both terms:
 *
 *  - `DepreciationService::run()` clamps the last charge at `cost − salvage`, so for ANY asset
 *    carrying a salvage value `cost <= Σ entries` can never become true. Measured 2026-09-04: all
 *    six rows on `mall_management_qa` carry a salvage value, so the worklist was empty by
 *    construction, for ever.
 *  - A legacy asset loaded at cut-over has its write-off in `opening_accumulated_depreciation` and
 *    no entries at all, so it scored zero however long it had been running.
 *
 * The screen's whole job is to find assets that can be retired, so the failure was silent in the
 * one direction nobody checks: an empty worklist reads as "nothing to write off".
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mall = makeAsset(['code' => 'SW193']);

    // Base 90,000 (100,000 − 10,000 salvage), 90,000 charged: finished.
    $this->salvaged = FixedAsset::create([
        'asset_id' => $this->mall->id, 'name' => 'Lift', 'tag' => 'SW193-SALVAGE',
        'acquisition_date' => '2019-01-01', 'acquisition_cost' => 100000, 'salvage_value' => 10000,
        'useful_life_months' => 90, 'method' => 'straight_line', 'funded_from' => 'cash',
    ]);
    DepreciationEntry::create([
        'fixed_asset_id' => $this->salvaged->id, 'period_month' => '2026-01-01', 'amount' => 90000,
    ]);

    // Written off in full before Atriom existed: no entries, the whole figure in the opening column.
    $this->legacy = FixedAsset::create([
        'asset_id' => $this->mall->id, 'name' => 'Old switchgear', 'tag' => 'SW193-LEGACY',
        'acquisition_date' => '2016-01-01', 'acquisition_cost' => 50000, 'salvage_value' => 0,
        'useful_life_months' => 120, 'method' => 'straight_line', 'funded_from' => 'cash',
        'is_opening_balance' => true, 'opening_accumulated_depreciation' => 50000,
    ]);

    // Base 90,000, 10,000 charged: still running.
    $this->running = FixedAsset::create([
        'asset_id' => $this->mall->id, 'name' => 'New lift', 'tag' => 'SW193-RUNNING',
        'acquisition_date' => '2026-01-01', 'acquisition_cost' => 100000, 'salvage_value' => 10000,
        'useful_life_months' => 90, 'method' => 'straight_line', 'funded_from' => 'cash',
    ]);
    DepreciationEntry::create([
        'fixed_asset_id' => $this->running->id, 'period_month' => '2026-02-01', 'amount' => 10000,
    ]);

    $this->actingAs(makeUser('accounting', [$this->mall->id]));
});

it('finds an asset that has reached its depreciable base, salvage and all', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListFixedAssets::class)
            ->filterTable('fully_depreciated')
            ->assertCanSeeTableRecords([$this->salvaged])
            ->assertCanNotSeeTableRecords([$this->running]);
    });
});

it('finds a legacy asset whose write-off predates the system', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListFixedAssets::class)
            ->filterTable('fully_depreciated')
            ->assertCanSeeTableRecords([$this->legacy])
            ->assertCanNotSeeTableRecords([$this->running]);
    });
});

it('leaves every asset on the list when the filter is off', function () {
    // THE CONTROL. A filter that matched everything would satisfy both assertions above; this is
    // what says the narrowing is the filter's and not the register's.
    asTenant($this->mall, function () {
        Livewire::test(ListFixedAssets::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->salvaged, $this->legacy, $this->running]);
    });
});

it('answers accumulated depreciation the same way in SQL as in PHP', function () {
    // The seam itself. The filter and the register's derived `accumulated` column are now ONE
    // expression, and that expression must agree with `FixedAsset::accumulatedDepreciation()` —
    // the rule this module has already had to un-drift once, across four readers.
    asTenant($this->mall, function () {
        foreach ([$this->salvaged, $this->legacy, $this->running] as $asset) {
            $viaSql = FixedAssetResource::getEloquentQuery()->whereKey($asset->id)->first();

            expect(round((float) $viaSql->accumulated, 2))
                ->toBe($asset->accumulatedDepreciation(), "accumulated drifted for {$asset->tag}");
        }
    });
});
