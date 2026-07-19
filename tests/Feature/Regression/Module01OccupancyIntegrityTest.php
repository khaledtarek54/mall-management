<?php

use App\Models\Lease;
use App\Models\Unit;

/**
 * Module-01 close-out — occupancy-projection integrity. Unit status is a projection of the unit's
 * leases (via the lease_unit pivot); these guard the lifecycle edges the gap sweep found unhooked.
 */

it('frees the unit when an active lease is soft-deleted (occupancy recomputes)', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'vacant']);
    $lease = makeLease($unit); // active → LeaseObserver flips the unit to occupied

    expect($unit->fresh()->status)->toBe('occupied')
        ->and($asset->fresh()->occupancyRate())->toBe(100.0);

    $lease->delete(); // super_admin DeleteAction = soft delete

    expect($unit->fresh()->status)->toBe('vacant')
        ->and($asset->fresh()->occupancyRate())->toBe(0.0);
});

it('re-occupies the unit when a soft-deleted lease is restored', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'vacant']);
    $lease = makeLease($unit);
    $lease->delete();
    expect($unit->fresh()->status)->toBe('vacant');

    $lease->restore();

    expect($unit->fresh()->status)->toBe('occupied');
});

it('frees the unit when an active lease is force-deleted', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'vacant']);
    $lease = makeLease($unit);
    expect($unit->fresh()->status)->toBe('occupied');

    $lease->forceDelete();

    expect($unit->fresh()->status)->toBe('vacant');
});

it('frees an ADDITIONAL unit of a multi-unit lease on delete', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['status' => 'vacant']);
    $b = makeUnit($asset, ['status' => 'vacant']);
    $lease = makeLease($a);
    $lease->syncUnits([$a->id, $b->id], $a->id); // a master, b additional — both occupied
    expect($b->fresh()->status)->toBe('occupied');

    $lease->delete();

    expect($a->fresh()->status)->toBe('vacant')
        ->and($b->fresh()->status)->toBe('vacant');
});

it('blocks double-booking a unit held only as an ADDITIONAL unit (pivot, not master pointer)', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['status' => 'vacant']);
    $b = makeUnit($asset, ['status' => 'vacant']);
    $l1 = makeLease($a);
    $l1->syncUnits([$a->id, $b->id], $a->id); // b is additional (pivot), not any lease's unit_id

    // The OLD guard (leases.unit_id only) would MISS b — it is nobody's master pointer…
    expect(Lease::where('unit_id', $b->id)->where('status', 'active')->exists())->toBeFalse();
    // …but the pivot-aware guard correctly sees b is actively leased.
    expect($b->fresh()->isActivelyLeased())->toBeTrue()
        ->and($a->fresh()->isActivelyLeased())->toBeTrue();
});

it('self-heals a lease-less unit set to occupied/reserved back to vacant, preserving maintenance', function () {
    $asset = makeAsset();

    $bogus = makeUnit($asset, ['status' => 'occupied']); // no lease backs it
    $bogus->recomputeStatus();
    expect($bogus->fresh()->status)->toBe('vacant');

    $reserved = makeUnit($asset, ['status' => 'reserved']);
    $reserved->recomputeStatus();
    expect($reserved->fresh()->status)->toBe('vacant');

    $maint = makeUnit($asset, ['status' => 'maintenance']);
    $maint->recomputeStatus();
    expect($maint->fresh()->status)->toBe('maintenance'); // manual override preserved
});
