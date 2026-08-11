<?php

use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Two guards on the fixed-asset register that were not where they claimed to be (module 23
 * close-out, 2026-08-11). Both are the sweep's central pattern, in a module the roadmap records as
 * never gap-analysed.
 *
 * **1. The re-cost floor was layer 3 only.** `DepreciationService::assertRecostValid()` refuses a
 * cost/salvage pair whose depreciable base would fall below what has already been charged — its own
 * docblock says why: "accumulated (60,000) now exceeds the new base (30,000) … the ledger carries
 * −30,000 of net fixed assets". It had exactly ONE caller in the codebase, `EditFixedAsset`, a
 * Filament page. Every other writer — an import, the console, a factory, a future screen — walked
 * straight past it into a negative net book value.
 *
 * **2. A DISPOSED asset's money fields stayed editable.** Disposal is terminal and posts a
 * write-off (Dr Accumulated Depreciation + proceeds, Cr the asset's cost, gain/loss to the P&L).
 * `FixedAsset::updated` then *deliberately* re-derives the child entries when `acquisition_cost`
 * changes — correct for a live asset whose cost is genuinely corrected, and exactly wrong for one
 * that has been sold: it restates a terminal, already-posted disposal, changing the gain or loss on
 * a sale that already happened, in a period that may since have closed. The acquisition entry moves
 * with it while the disposal's credit does not agree, so Furniture & Equipment is left carrying an
 * asset the company no longer owns.
 *
 * Both now sit on the model, which this module has always used as its choke point — it has no
 * create/update service, and the docblock beside the posting-date guard says so.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'FA1']);

    $this->fixed = FixedAsset::create([
        'asset_id' => $this->asset->id,
        'name' => 'Chiller unit', 'tag' => 'FA-001', 'category' => 'hvac',
        'acquisition_date' => '2026-01-01',
        'acquisition_cost' => 100000, 'salvage_value' => 0,
        'useful_life_months' => 100, 'method' => 'straight_line',
        'status' => 'active',
    ]);
});

/** Charge $months of depreciation, one period at a time, the way the real run does. */
function depreciateMonths(FixedAsset $fixed, int $months): void
{
    $svc = app(DepreciationService::class);

    for ($i = 0; $i < $months; $i++) {
        $svc->run(\Carbon\CarbonImmutable::parse('2026-01-01')->addMonths($i));
    }
}

it('refuses a re-cost below what has already been depreciated, from any writer', function () {
    depreciateMonths($this->fixed, 6);   // 6 x 1,000 = 6,000 accumulated

    expect(round(app(DepreciationService::class)->accumulatedFor($this->fixed->fresh()), 2))->toBe(6000.0);

    // A direct model write — an import, the console, a future screen. The Filament page was the
    // only thing standing here.
    expect(fn () => $this->fixed->fresh()->update(['acquisition_cost' => 3000]))
        ->toThrow(DomainException::class);
});

it('allows a re-cost that still covers what has been charged', function () {
    // The control: the floor is what has ALREADY been depreciated, not a freeze on the column.
    depreciateMonths($this->fixed, 6);

    expect(fn () => $this->fixed->fresh()->update(['acquisition_cost' => 80000]))
        ->not->toThrow(DomainException::class);

    expect(round((float) $this->fixed->fresh()->acquisition_cost, 2))->toBe(80000.0);
});

it('counts salvage value in the floor, because the base is cost minus salvage', function () {
    depreciateMonths($this->fixed, 6);

    // Cost 120,000 with salvage 118,000 leaves a base of 2,000 — under the 6,000 charged.
    expect(fn () => $this->fixed->fresh()->update([
        'acquisition_cost' => 120000, 'salvage_value' => 118000,
    ]))->toThrow(DomainException::class);
});

it('freezes a disposed asset\'s money fields', function () {
    depreciateMonths($this->fixed, 6);
    app(DisposeFixedAssetService::class)->dispose($this->fixed->fresh(), [
        'disposed_on' => '2026-07-01', 'proceeds' => 50000,
    ]);

    // Restating the cost of a SOLD asset re-derives its terminal disposal entry — a different
    // gain or loss on a sale that already happened.
    expect(fn () => $this->fixed->fresh()->update(['acquisition_cost' => 200000]))
        ->toThrow(DomainException::class);

    expect(round((float) $this->fixed->fresh()->acquisition_cost, 2))->toBe(100000.0);
});

it('still allows housekeeping on a disposed asset', function () {
    // The control, and a real need: an operator must be able to correct a tag or a name after
    // disposal without the register refusing every save.
    depreciateMonths($this->fixed, 6);
    app(DisposeFixedAssetService::class)->dispose($this->fixed->fresh(), [
        'disposed_on' => '2026-07-01', 'proceeds' => 50000,
    ]);

    expect(fn () => $this->fixed->fresh()->update(['name' => 'Chiller unit (roof, north)']))
        ->not->toThrow(DomainException::class);
});

it('lets the disposal itself set the terminal fields', function () {
    // Without this carve-out the guard would block the very act of disposing.
    depreciateMonths($this->fixed, 6);

    $disposal = app(DisposeFixedAssetService::class)->dispose($this->fixed->fresh(), [
        'disposed_on' => '2026-07-01', 'proceeds' => 50000,
    ]);

    expect($disposal->exists)->toBeTrue()
        ->and($this->fixed->fresh()->status)->toBe('disposed');
});
