<?php

/**
 * Feature #14 — multi-unit lease: DATA / lifecycle / observer scenarios.
 *
 * Source of truth is the lease_unit pivot (unit_id + is_master); leases.unit_id
 * mirrors the MASTER. Occupancy of every unit is projected by Unit::recomputeStatus()
 * from the leases that include it (via the pivot), driven by LeaseObserver + syncUnits.
 *
 * recomputeStatus projection (see Unit.php):
 *   any 'active'                              → occupied
 *   any draft / pending_approval / renewed    → reserved
 *   otherwise (expired/terminated/cancelled)  → vacant
 *   'maintenance' is a manual override, never auto-overwritten.
 *
 * These are NET-NEW relative to tests/Feature/MultiUnitLeaseTest.php — that file
 * covers single→master mirroring, basic multi-unit, master move, drop-a-unit and
 * the Filament form/table. Here we exhaustively exercise the status projection for
 * BOTH master and additional units, syncUnits edge cases, pivot uniqueness, the
 * maintenance override and Asset::occupancyRate.
 */

use App\Models\Lease;
use App\Models\Unit;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/*
|--------------------------------------------------------------------------
| Occupancy projection per lease status — master + additional unit
|--------------------------------------------------------------------------
| Every status is asserted against BOTH the master and an additional unit
| so the multi-unit pivot path is proven to project identically to the
| single-unit (unit_id) path.
*/

dataset('lease_status_to_unit_status', [
    'draft            → reserved' => ['draft', 'reserved'],
    'pending_approval → reserved' => ['pending_approval', 'reserved'],
    'renewed          → reserved' => ['renewed', 'reserved'],
    'active           → occupied' => ['active', 'occupied'],
    'expired          → vacant'   => ['expired', 'vacant'],
    'terminated       → vacant'   => ['terminated', 'vacant'],
    'cancelled        → vacant'   => ['cancelled', 'vacant'],
]);

it('projects the master unit status from the lease status', function (string $leaseStatus, string $expected) {
    $asset = makeAsset();
    $master = makeUnit($asset, ['status' => 'vacant']);

    makeLease($master, null, ['status' => $leaseStatus]);

    expect($master->fresh()->status)->toBe($expected);
})->with('lease_status_to_unit_status');

it('projects an additional unit status identically to the master', function (string $leaseStatus, string $expected) {
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'M-01', 'status' => 'vacant']);
    $extra = makeUnit($asset, ['code' => 'E-01', 'status' => 'vacant']);

    $lease = makeLease($master, null, ['status' => $leaseStatus]);
    $lease->syncUnits([$master->id, $extra->id], $master->id);

    expect($master->fresh()->status)->toBe($expected)
        ->and($extra->fresh()->status)->toBe($expected);
})->with('lease_status_to_unit_status');

it('re-projects every unit when the lease status transitions active → terminated', function () {
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'M-01']);
    $extra = makeUnit($asset, ['code' => 'E-01']);

    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $extra->id], $master->id);
    expect($master->fresh()->status)->toBe('occupied')
        ->and($extra->fresh()->status)->toBe('occupied');

    // status change fires LeaseObserver::updated → recompute every attached unit
    $lease->update(['status' => 'terminated']);

    expect($master->fresh()->status)->toBe('vacant')
        ->and($extra->fresh()->status)->toBe('vacant');
});

it('lifts every unit from reserved to occupied when a draft lease activates', function () {
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'M-01']);
    $extra = makeUnit($asset, ['code' => 'E-01']);

    $lease = makeLease($master, null, ['status' => 'draft']);
    $lease->syncUnits([$master->id, $extra->id], $master->id);
    expect($master->fresh()->status)->toBe('reserved')
        ->and($extra->fresh()->status)->toBe('reserved');

    $lease->update(['status' => 'active']);

    expect($master->fresh()->status)->toBe('occupied')
        ->and($extra->fresh()->status)->toBe('occupied');
});

/*
|--------------------------------------------------------------------------
| Master reassignment via syncUnits
|--------------------------------------------------------------------------
*/

it('demotes the old master and mirrors the new master into leases.unit_id', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01']);
    $b = makeUnit($asset, ['code' => 'B-01']);

    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $b->id], $a->id);

    // sanity: A is master
    expect((int) $lease->fresh()->unit_id)->toBe($a->id);

    // promote B
    $lease->syncUnits([$a->id, $b->id], $b->id);

    $masterFlags = $lease->units()->get()->mapWithKeys(
        fn (Unit $u) => [$u->id => (bool) $u->pivot->is_master],
    );

    expect((int) $lease->fresh()->unit_id)->toBe($b->id)        // unit_id mirrors new master
        ->and($masterFlags[$b->id])->toBeTrue()                  // B promoted
        ->and($masterFlags[$a->id])->toBeFalse()                 // A demoted
        ->and($lease->units()->wherePivot('is_master', true)->count())->toBe(1) // exactly one master
        ->and($a->fresh()->status)->toBe('occupied')             // both still occupied
        ->and($b->fresh()->status)->toBe('occupied');
});

it('frees the demoted-and-removed master while the surviving unit becomes master', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01']);
    $b = makeUnit($asset, ['code' => 'B-01']);

    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $b->id], $a->id);  // A master, both occupied

    // remove the master A entirely, keep B (which becomes the new master)
    $lease->syncUnits([$b->id], $b->id);

    expect($lease->units()->pluck('units.id')->all())->toBe([$b->id])
        ->and((int) $lease->fresh()->unit_id)->toBe($b->id)      // unit_id followed to surviving unit
        ->and($a->fresh()->status)->toBe('vacant')               // dropped master freed
        ->and($b->fresh()->status)->toBe('occupied');
});

/*
|--------------------------------------------------------------------------
| syncUnits edge cases
|--------------------------------------------------------------------------
*/

it('treats an empty unit array as a no-op (keeps the existing pivot)', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01']);
    $b = makeUnit($asset, ['code' => 'B-01']);

    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $b->id], $a->id);

    $before = $lease->units()->pluck('units.id')->sort()->values()->all();

    $lease->syncUnits([], null);  // no-op

    expect($lease->units()->pluck('units.id')->sort()->values()->all())->toBe($before)
        ->and((int) $lease->fresh()->unit_id)->toBe($a->id)
        ->and($a->fresh()->status)->toBe('occupied')
        ->and($b->fresh()->status)->toBe('occupied');
});

it('falls back to the first id as master when the given master is not in the set', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01']);
    $b = makeUnit($asset, ['code' => 'B-01']);
    $orphan = makeUnit($asset, ['code' => 'O-01']);

    $lease = makeLease($a, null, ['status' => 'active']);
    // master = $orphan->id, which is NOT in [a, b] → first id (a) wins
    $lease->syncUnits([$a->id, $b->id], $orphan->id);

    expect((int) $lease->fresh()->unit_id)->toBe($a->id)
        ->and((bool) $lease->units()->where('units.id', $a->id)->first()->pivot->is_master)->toBeTrue()
        ->and((bool) $lease->units()->where('units.id', $b->id)->first()->pivot->is_master)->toBeFalse()
        ->and($orphan->fresh()->status)->toBe('vacant'); // orphan never attached
});

it('defaults the master to the first id when none is supplied', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01']);
    $b = makeUnit($asset, ['code' => 'B-01']);

    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $b->id]); // no master arg

    expect((int) $lease->fresh()->unit_id)->toBe($a->id)
        ->and($lease->units()->wherePivot('is_master', true)->pluck('units.id')->all())->toBe([$a->id]);
});

it('dedupes duplicate ids in the sync set', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01']);
    $b = makeUnit($asset, ['code' => 'B-01']);

    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $a->id, $b->id, $b->id, $a->id], $a->id);

    expect($lease->units()->count())->toBe(2)
        ->and($lease->units()->pluck('units.id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    // and no duplicate pivot rows physically exist
    $rows = DB::table('lease_unit')->where('lease_id', $lease->id)->count();
    expect($rows)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Maintenance manual override is sacred
|--------------------------------------------------------------------------
*/

it('never overwrites a maintenance unit via recomputeStatus', function () {
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'M-01']);
    $maint = makeUnit($asset, ['code' => 'X-01', 'status' => 'maintenance']);

    $lease = makeLease($master, null, ['status' => 'active']);
    // attach the maintenance unit as an additional unit of an active lease
    $lease->syncUnits([$master->id, $maint->id], $master->id);

    // active lease would normally force 'occupied' — maintenance must survive
    expect($maint->fresh()->status)->toBe('maintenance')
        ->and($master->fresh()->status)->toBe('occupied');

    // a direct recompute is still a no-op
    $maint->fresh()->recomputeStatus();
    expect($maint->fresh()->status)->toBe('maintenance');
});

it('keeps maintenance even when the lease is later terminated', function () {
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'M-01']);
    $maint = makeUnit($asset, ['code' => 'X-01', 'status' => 'maintenance']);

    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $maint->id], $master->id);

    $lease->update(['status' => 'terminated']);

    expect($maint->fresh()->status)->toBe('maintenance')   // still overridden
        ->and($master->fresh()->status)->toBe('vacant');   // master fell free
});

/*
|--------------------------------------------------------------------------
| Idempotency / no duplicate pivot rows
|--------------------------------------------------------------------------
*/

it('creates no duplicate pivot rows when syncUnits is re-run with the same set', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01']);
    $b = makeUnit($asset, ['code' => 'B-01']);

    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $b->id], $a->id);
    $lease->syncUnits([$a->id, $b->id], $a->id);
    $lease->syncUnits([$a->id, $b->id], $a->id);

    $rows = DB::table('lease_unit')->where('lease_id', $lease->id)->count();
    expect($rows)->toBe(2)
        ->and($lease->units()->wherePivot('is_master', true)->count())->toBe(1);
});

it('creates no duplicate master pivot row when the observer re-fires on status update', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);

    $lease = makeLease($unit, null, ['status' => 'draft']);
    // created observer already mirrored one master row
    expect(DB::table('lease_unit')->where('lease_id', $lease->id)->count())->toBe(1);

    // updated observer (status changed) re-runs ensureMasterPivot via syncWithoutDetaching
    $lease->update(['status' => 'active']);
    $lease->update(['status' => 'terminated']);

    expect(DB::table('lease_unit')->where('lease_id', $lease->id)->count())->toBe(1)
        ->and($lease->units()->wherePivot('is_master', true)->count())->toBe(1);
});

it('detaches the old unit and creates no orphan/duplicate row on a single-unit reassignment', function () {
    $asset = makeAsset();
    $old = makeUnit($asset, ['code' => 'OLD']);
    $new = makeUnit($asset, ['code' => 'NEW', 'status' => 'vacant']);

    $lease = makeLease($old, null, ['status' => 'active']);
    expect($old->fresh()->status)->toBe('occupied');

    // single-unit reassignment via unit_id (observer handles detach + promote)
    $lease->update(['unit_id' => $new->id]);

    expect($lease->units()->pluck('units.id')->all())->toBe([$new->id]) // only the new unit
        ->and(DB::table('lease_unit')->where('lease_id', $lease->id)->count())->toBe(1)
        ->and((int) $lease->fresh()->unit_id)->toBe($new->id)
        ->and($old->fresh()->status)->toBe('vacant')        // old unit freed
        ->and($new->fresh()->status)->toBe('occupied');     // new unit occupied
});

/*
|--------------------------------------------------------------------------
| "Freed only when no remaining non-terminal lease"
|--------------------------------------------------------------------------
*/

it('keeps a unit occupied while any active lease still includes it', function () {
    $asset = makeAsset();
    $shared = makeUnit($asset, ['code' => 'S-01']);
    $other = makeUnit($asset, ['code' => 'O-01']);

    // Lease 1: active, covers shared + other
    $lease1 = makeLease($shared, null, ['status' => 'active']);
    $lease1->syncUnits([$shared->id, $other->id], $shared->id);

    // Lease 2: a terminated lease also references the shared unit (history)
    $lease2 = makeLease($other, null, ['status' => 'active']);
    $lease2->syncUnits([$shared->id, $other->id], $other->id);
    $lease2->update(['status' => 'terminated']);

    // shared still has lease1 active → must remain occupied even though lease2 ended
    expect($shared->fresh()->status)->toBe('occupied');

    // now terminate lease1 too — no non-terminal lease left → freed
    $lease1->update(['status' => 'terminated']);
    expect($shared->fresh()->status)->toBe('vacant');
});

it('keeps a unit reserved while a draft lease remains after an active one ends', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['code' => 'U-01']);

    $active = makeLease($unit, null, ['status' => 'active']);
    expect($unit->fresh()->status)->toBe('occupied');

    // a future draft lease also references the same unit
    $draft = makeLease($unit, null, ['status' => 'draft']);
    // active still present → occupied wins
    expect($unit->fresh()->status)->toBe('occupied');

    // end the active lease — draft remains → reserved, not vacant
    $active->update(['status' => 'expired']);
    expect($unit->fresh()->status)->toBe('reserved');
});

/*
|--------------------------------------------------------------------------
| Asset::occupancyRate reflects multi-unit leases
|--------------------------------------------------------------------------
*/

it('reflects a multi-unit lease in Asset::occupancyRate', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01', 'status' => 'vacant']);
    $b = makeUnit($asset, ['code' => 'B-01', 'status' => 'vacant']);
    $c = makeUnit($asset, ['code' => 'C-01', 'status' => 'vacant']);
    $d = makeUnit($asset, ['code' => 'D-01', 'status' => 'vacant']);

    expect($asset->occupancyRate())->toBe(0.0);

    // one active lease spanning 3 of 4 units → 75%
    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $b->id, $c->id], $a->id);

    expect($asset->occupancyRate())->toBe(75.0)
        ->and($d->fresh()->status)->toBe('vacant');

    // shrink the lease to a single unit → 25%
    $lease->syncUnits([$a->id], $a->id);
    expect($asset->occupancyRate())->toBe(25.0);
});
