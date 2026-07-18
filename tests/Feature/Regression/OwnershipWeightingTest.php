<?php

use App\Filament\Owner\Widgets\PortfolioStats;
use App\Models\AssetOwner;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Slice 3 of the owner-statements module (docs/plans/04-owner-statements-disbursements.md):
 * `ownership_percentage` was recorded but ignored everywhere, so a 50% co-owner saw 100% of a
 * property's numbers. These pin the tenure-aware share helpers on User + the PortfolioStats
 * weighting fix (the visible bug) so it can't silently regress.
 */
it('reports the current ownership share per owned property', function () {
    $owner = makeUser('owner');
    $a = makeAsset();
    $b = makeAsset();
    $owner->ownedAssets()->attach($a->id, ['ownership_percentage' => 50]);
    $owner->ownedAssets()->attach($b->id, ['ownership_percentage' => 100]);

    $shares = $owner->currentOwnershipShares();

    expect($shares[$a->id])->toBe(50.0)
        ->and($shares[$b->id])->toBe(100.0);
});

it('casts the ownership pivot via AssetOwner (dates → Carbon) and answers coversDate()', function () {
    $owner = makeUser('owner');
    $a = makeAsset();
    $owner->ownedAssets()->attach($a->id, [
        'ownership_percentage' => 60,
        'started_at' => '2026-01-01',
        'ended_at' => '2026-12-31',
    ]);

    $pivot = $owner->ownedAssets()->first()->pivot;

    expect($pivot)->toBeInstanceOf(AssetOwner::class)
        ->and($pivot->started_at)->toBeInstanceOf(Carbon::class)
        ->and($pivot->started_at->toDateString())->toBe('2026-01-01')
        ->and($pivot->coversDate('2026-06-15'))->toBeTrue()
        ->and($pivot->coversDate('2027-01-01'))->toBeFalse()
        ->and($pivot->coversDate('2025-12-31'))->toBeFalse();
});

it('excludes ended and not-yet-started ownership from the current shares', function () {
    $owner = makeUser('owner');
    $ended = makeAsset();
    $future = makeAsset();
    $open = makeAsset();

    $owner->ownedAssets()->attach($ended->id, ['ownership_percentage' => 100, 'ended_at' => now()->subDay()->toDateString()]);
    $owner->ownedAssets()->attach($future->id, ['ownership_percentage' => 100, 'started_at' => now()->addDay()->toDateString()]);
    $owner->ownedAssets()->attach($open->id, ['ownership_percentage' => 100]); // null bounds = currently owned

    $shares = $owner->currentOwnershipShares();

    expect($shares->keys()->all())->toBe([$open->id])
        ->and($shares->has($ended->id))->toBeFalse()
        ->and($shares->has($future->id))->toBeFalse();
});

it('weights the portfolio widget MRR + AR by the ownership share (was: showed 100%)', function () {
    $owner = makeUser('owner');
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'occupied']);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 10000, 'service_charge_monthly' => 2000]); // asset MRR 12,000
    makeInvoice($lease, ['balance' => 8000, 'status' => 'issued']); // asset AR 8,000
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 50]);

    $this->actingAs($owner);

    Livewire::test(PortfolioStats::class)
        ->assertSee('EGP 6,000')       // MRR 12,000 × 50%
        ->assertSee('EGP 4,000')       // AR 8,000 × 50%
        ->assertDontSee('EGP 12,000')  // the un-weighted (old, buggy) MRR
        ->assertDontSee('EGP 8,000');  // the un-weighted (old, buggy) AR
});
