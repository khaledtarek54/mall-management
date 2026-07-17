<?php

use App\Models\FixedAsset;
use App\Services\DepreciationService;
use Carbon\CarbonImmutable;

/**
 * Regression — gap-analysis **F-86** (module 23): a re-cost below posted depreciation.
 *
 * THE BUG. `DepreciationService::run()` clamps the FORWARD charge with `min(monthly, remaining)`,
 * so it can never over-depreciate going forward. Nothing guarded a RETROACTIVE cost change.
 * `EditFixedAsset` blocked editing a *disposed* asset but left an *active* one's
 * `acquisition_cost`/`salvage_value` freely editable — and the model's `updated` hook explicitly
 * supports re-costing, so this is a supported operation. Reproduced by the audit:
 *
 *   cost 120,000, life 12 → 10,000/mo. After Jan–Jun: accumulated 60,000, NBV 60,000.
 *   Correct acquisition_cost 120,000 → 30,000 (asset still ACTIVE, edit allowed):
 *     base        = 30,000
 *     accumulated = 60,000   ← EXCEEDS the base
 *     NBV         = −30,000  ← NEGATIVE
 *   The July run creates 0 entries (remaining ≤ 0 → charging stops FOREVER), and the sweep
 *   re-derives the acquisition to Dr Furniture 30,000 while Accumulated stays Cr 60,000 — the
 *   balance sheet carries −30,000 of net fixed assets. Doc rules 2 and 4 ("accumulated tops out
 *   at cost − salvage, NEVER beyond") are about exactly this.
 *
 * THE FIX. `DepreciationService::assertRecostValid()` refuses a cost/salvage pair whose base would
 * fall below accumulated. Called server-side from `EditFixedAsset` (thrown as validation so it
 * renders on the field, not a 500) and inline on the form field so the operator sees it first.
 */
function recostAsset(array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => makeAsset()->id,
        'name' => 'Chiller',
        'tag' => 'FA-'.uniqid(),
        'acquisition_date' => '2026-01-01',
        'acquisition_cost' => 120000,
        'salvage_value' => 0,
        'useful_life_months' => 12, // 10,000 / month
        'method' => 'straight_line',
        'status' => 'active',
    ], $attrs));
}

beforeEach(fn () => $this->svc = app(DepreciationService::class));

it('refuses a re-cost that would drop the base below accumulated depreciation', function () {
    $asset = recostAsset();

    // Six months posted → accumulated 60,000.
    for ($i = 0; $i < 6; $i++) {
        $this->svc->run(CarbonImmutable::parse('2026-01-01')->addMonths($i), [$asset->asset_id]);
    }
    expect($this->svc->accumulatedFor($asset))->toBe(60000.0);

    // The exact edit the audit made: 120,000 → 30,000, base now below the 60,000 already charged.
    expect(fn () => $this->svc->assertRecostValid($asset, 30000, 0))
        ->toThrow(DomainException::class);
});

it('allows a re-cost that stays at or above accumulated depreciation', function () {
    $asset = recostAsset();
    for ($i = 0; $i < 6; $i++) {
        $this->svc->run(CarbonImmutable::parse('2026-01-01')->addMonths($i), [$asset->asset_id]);
    }

    // 80,000 base is still above the 60,000 charged — a legitimate correction (the invoice was
    // over-stated, but by less than the audit's version). Must be allowed.
    $this->svc->assertRecostValid($asset, 80000, 0); // does not throw

    // Exactly equal is the boundary and is allowed — the asset is simply now fully depreciated.
    $this->svc->assertRecostValid($asset, 60000, 0);

    expect(true)->toBeTrue();
});

it('judges cost and salvage as a pair — the base is cost minus salvage', function () {
    $asset = recostAsset();
    for ($i = 0; $i < 6; $i++) {
        $this->svc->run(CarbonImmutable::parse('2026-01-01')->addMonths($i), [$asset->asset_id]);
    }

    // Cost 120,000 is fine alone, but a 70,000 salvage drops the base to 50,000 — below 60,000.
    expect(fn () => $this->svc->assertRecostValid($asset, 120000, 70000))
        ->toThrow(DomainException::class);
});

it('keeps depreciation running after a legitimate downward re-cost', function () {
    // The point of the whole guard: the books must stay sane. Prove that an ALLOWED re-cost
    // leaves NBV non-negative and the schedule still charging.
    $asset = recostAsset();
    for ($i = 0; $i < 6; $i++) {
        $this->svc->run(CarbonImmutable::parse('2026-01-01')->addMonths($i), [$asset->asset_id]);
    }

    $this->svc->assertRecostValid($asset, 80000, 0);
    $asset->update(['acquisition_cost' => 80000]);

    expect($this->svc->netBookValue($asset->fresh()))->toBeGreaterThanOrEqual(0.0);

    // July still charges — the schedule didn't stall.
    expect($this->svc->run(CarbonImmutable::parse('2026-07-01'), [$asset->asset_id]))->toBe(1);
});

it('does not constrain a brand-new asset with nothing depreciated yet', function () {
    // On create, accumulated is 0, so any non-negative base is fine — the guard is edit-only.
    $asset = recostAsset(['acquisition_cost' => 5000]);

    $this->svc->assertRecostValid($asset, 5000, 0);   // fine
    $this->svc->assertRecostValid($asset, 100, 0);    // also fine — nothing charged
    expect(true)->toBeTrue();
});
