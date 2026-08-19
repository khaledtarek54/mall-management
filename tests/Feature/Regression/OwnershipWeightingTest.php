<?php

use App\Models\AssetOwner;
use Illuminate\Support\Carbon;

/**
 * Ownership-tenure infrastructure (docs/modules/32-owner-statements.md): the ownership
 * pivot is cast at source (AssetOwner) and tenure-aware, so a sold-off stake drops out of the
 * current set.
 *
 * **What is production and what is retained.** `currentOwnedAssets()` IS production — `AssignedAssets`,
 * `OwnerPack` and `BuildOwnerPackService` all read it, so tenure decides an owner's reach. Its
 * `currentOwnershipShares()` wrapper has no caller and is retained for a possible absolute-weighting
 * build (gap analysis 32, F-A option 1). The tenure rule is therefore asserted against BOTH: through
 * the wrapper, and directly on the relation the product actually calls — otherwise the rule that
 * matters would be covered only incidentally, by a test of code nothing runs.
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

    // The same rule on the relation PRODUCTION reads — `AssignedAssets` and the owner pack narrow an
    // owner's reach through this, so an expired tenure must stop granting access here, not only in
    // the unused wrapper above.
    expect($owner->currentOwnedAssets()->pluck('assets.id')->all())->toBe([$open->id]);
});
