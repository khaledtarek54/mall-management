<?php

use App\Models\Unit;

/**
 * What a unit's status means, and who is allowed to set it (module 01 close-out).
 *
 * Every state is derived from the leases that include the unit — except `maintenance`, which is a
 * manual override the derivation must never overwrite. That guard is the whole reason
 * `DeletionPolicy` can tell an operator to "set the unit to maintenance if it is out of service"
 * instead of deleting it: if the status did not stick, that instruction would be a lie.
 */
it('never overwrites a manual maintenance status', function () {
    $unit = makeUnit(makeAsset(), ['code' => 'M-1', 'status' => 'maintenance']);

    $unit->recomputeStatus();

    expect($unit->fresh()->status)->toBe('maintenance');
});

it('derives vacant, reserved and occupied from the leases that hold the unit', function () {
    $asset = makeAsset();

    $free = makeUnit($asset, ['code' => 'U-1']);
    $free->recomputeStatus();
    expect($free->fresh()->status)->toBe('vacant');

    $let = makeUnit($asset, ['code' => 'U-2']);
    makeLease($let, null, ['status' => 'active']);
    expect($let->fresh()->status)->toBe('occupied');

    // A signed-but-not-started lease SPEAKS FOR the space without occupying it — the distinction a
    // leasing manager needs before marketing it.
    $spoken = makeUnit($asset, ['code' => 'U-3']);
    makeLease($spoken, null, ['status' => 'draft']);
    expect($spoken->fresh()->status)->toBe('reserved');
});

it('offers a unit under maintenance to a lease, because refurbished space is still lettable', function () {
    // The two unit pickers on the lease form used to disagree: a unit under refurbishment could be
    // a lease's MASTER premises but not its annexe, for no stated reason. Letting space that is
    // being refurbished is ordinary — that is what a fit-out period is for.
    $asset = makeAsset();
    makeUnit($asset, ['code' => 'R-1', 'status' => 'maintenance']);

    $offered = Unit::where('asset_id', $asset->id)
        ->whereNotIn('status', ['occupied', 'reserved'])
        ->pluck('code');

    expect($offered)->toContain('R-1');
});
