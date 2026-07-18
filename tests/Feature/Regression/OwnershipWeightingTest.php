<?php

use App\Filament\Owner\Widgets\PortfolioStats;
use App\Models\AssetOwner;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Slice 3 of the owner-statements module (docs/plans/04-owner-statements-disbursements.md),
 * as revised by the operator's "one owner per mall, gets 100%" decision (2026-07-19):
 *   - the ownership pivot is cast at source (AssetOwner) and tenure-aware, so a sold-off stake
 *     drops out — this infrastructure is RETAINED for a future multi-owner build;
 *   - but the ownership-% split is NOT applied to money for now: the single owner sees the FULL
 *     property figures. These pin both halves so neither silently regresses.
 */
it('reports the current ownership share per owned property (infrastructure, retained for later)', function () {
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

it('excludes ended and not-yet-started ownership from the current set', function () {
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

it('shows the owner the FULL money of a currently-owned property (no % split for now)', function () {
    $owner = makeUser('owner');
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'occupied']);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 10000, 'service_charge_monthly' => 2000]); // MRR 12,000
    makeInvoice($lease, ['balance' => 8000, 'status' => 'issued']); // AR 8,000
    // Even with a co-owner % recorded on the pivot, the owner sees the FULL property money —
    // the operator's model is one owner = 100%, and the % split is deferred.
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 50]);

    $this->actingAs($owner);

    Livewire::test(PortfolioStats::class)
        ->assertSee('EGP 12,000')  // full MRR, NOT weighted down to 6,000
        ->assertSee('EGP 8,000')   // full AR, NOT weighted down to 4,000
        ->assertDontSee('EGP 6,000');
});

it('drops a sold-off (ended-tenure) property from the owner widget', function () {
    $owner = makeUser('owner');
    $current = makeAsset();
    $sold = makeAsset();
    makeLease(makeUnit($current, ['status' => 'occupied']), null, ['base_rent_monthly' => 5000, 'service_charge_monthly' => 0]);
    makeLease(makeUnit($sold, ['status' => 'occupied']), null, ['base_rent_monthly' => 9999, 'service_charge_monthly' => 0]);
    $owner->ownedAssets()->attach($current->id, ['ownership_percentage' => 100]);
    $owner->ownedAssets()->attach($sold->id, ['ownership_percentage' => 100, 'ended_at' => now()->subDay()->toDateString()]);

    $this->actingAs($owner);

    Livewire::test(PortfolioStats::class)
        ->assertSee('EGP 5,000')     // the currently-owned mall's MRR
        ->assertDontSee('9,999');    // the sold mall is excluded
});
