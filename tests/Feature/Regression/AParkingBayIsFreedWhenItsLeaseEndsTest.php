<?php

/**
 * A bay whose lease has ended reads AVAILABLE on the register.
 *
 * `rentable_items.status` is a stored column that is a function of TODAY, which is the shape
 * `App\Support\ProjectedState` exists for: it goes wrong on a day when NOTHING HAPPENED. A lease
 * reaching its expiry date is not a write, so nothing fired — and it was in neither `PROJECTIONS`
 * nor `NOT_PROJECTED`, i.e. nobody had decided which it was.
 *
 * Measured before the fix: assign a bay to a lease, let the term run out, run `leases:expire`. The
 * lease moves to `expired`, `isHeldOn()` correctly answers false, the holding row still carries
 * `effective_to = NULL` — and `status` still says `assigned`. For ever.
 *
 * **What that did and did not break.** The bay stayed re-lettable throughout:
 * `RentableItemOptions::lettable()` rejects on `isHeldOn()`, which reads the HOLDER's liveness, and
 * never on this column. So nothing failed and no double-let was possible — a screen simply
 * under-reported. An operator filtering the register for *Available* to find a free bay could not
 * see it. That is precisely why it survived: the failure had no error in it.
 *
 * **The subtlety that makes a naive fix wrong.** `status` does not mean "occupied today", it means
 * "off the market" — `AssignRentableItemService::release()` marks a bay released effective 30 June
 * as AVAILABLE the moment the release is recorded, so it can be let from July, and
 * `RentableItemAssignmentTest` pins that. A projection built on `isHeldOn(today)` would have
 * fought it and flipped the bay back to `assigned` on the next nightly run. So the projector asks
 * the OTHER question — `isSpokenFor()`: held open-endedly by a LIVE agreement — and the two
 * meanings are kept apart deliberately.
 */

use App\Enums\UnitOwnershipStatus;
use App\Models\RentableItem;
use App\Models\UnitOwnership;
use App\Services\AssignRentableItemService;
use App\Support\ProjectedState;
use App\Support\RentableItemOptions;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'PK']);
    $this->service = app(AssignRentableItemService::class);
});

/** A bay in the property, free. */
function bayIn($asset, string $code = 'P-01'): RentableItem
{
    return RentableItem::create([
        'asset_id' => $asset->id,
        'code' => $code,
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => 500,
        'status' => RentableItem::STATUS_AVAILABLE,
    ]);
}

it('frees the bay when the lease holding it expires', function () {
    $lease = makeLease(makeUnit($this->asset), null, [
        'status' => 'active',
        'start_date' => CarbonImmutable::now()->subYear()->toDateString(),
        'expiry_date' => CarbonImmutable::now()->subMonth()->toDateString(),
    ]);
    $bay = bayIn($this->asset);

    $this->service->assign($lease, $bay, [
        'monthly_rate' => 500,
        'effective_from' => CarbonImmutable::now()->subYear()->toDateString(),
    ]);

    // The control: while the lease runs, the bay is off the market.
    expect($bay->fresh()->status)->toBe(RentableItem::STATUS_ASSIGNED);

    $this->artisan('leases:expire')->assertSuccessful();

    expect($lease->fresh()->status)->toBe('expired')
        ->and($bay->fresh()->status)->toBe(RentableItem::STATUS_AVAILABLE);

    // The holding row is deliberately NOT closed — the lease's own liveness is what frees the bay,
    // and rewriting history on a pivot would lose when the tenancy actually ran.
    expect(DB::table('rentable_item_holdings')->where('rentable_item_id', $bay->id)->value('effective_to'))
        ->toBeNull();
});

it('does not flip a bay released for a FUTURE date back to assigned', function () {
    // The regression a naive projection would have introduced. `release()` means "free to let from
    // then", and the nightly sweep must not disagree with it.
    CarbonImmutable::setTestNow('2026-03-05');

    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active', 'expiry_date' => '2027-12-31']);
    $bay = bayIn($this->asset);

    $this->service->assign($lease, $bay, ['effective_from' => '2026-03-01']);
    $this->service->release($lease->fresh(), $bay->fresh(), '2026-06-30');

    expect($bay->fresh()->status)->toBe(RentableItem::STATUS_AVAILABLE);

    $this->artisan('leases:expire')->assertSuccessful();

    expect($bay->fresh()->status)->toBe(RentableItem::STATUS_AVAILABLE);

    CarbonImmutable::setTestNow();
});

it('never overwrites an out-of-service bay', function () {
    // A manual override, the same rule `maintenance` gets on a unit. A bay taken out for resurfacing
    // must not be quietly offered again by a sweep.
    $bay = bayIn($this->asset);
    $bay->update(['status' => RentableItem::STATUS_OUT_OF_SERVICE]);

    $this->artisan('leases:expire')->assertSuccessful();

    expect($bay->fresh()->status)->toBe(RentableItem::STATUS_OUT_OF_SERVICE);
});

it('keeps a bay held by a live unit ownership off the market', function () {
    // The other holder. `isSpokenFor()` asks per holder for the reason `isHeldOn()` does — a bay
    // held by an owner-occupier would otherwise look free to the next lease.
    $unit = makeUnit($this->asset);
    $owner = makeTenant();

    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => CarbonImmutable::now()->subYear()->toDateString(),
        'payment_terms_days' => 10,
    ]);

    $bay = bayIn($this->asset);
    $this->service->assign($ownership, $bay, ['monthly_rate' => 500]);

    expect($bay->fresh()->status)->toBe(RentableItem::STATUS_ASSIGNED);

    $this->artisan('leases:expire')->assertSuccessful();

    expect($bay->fresh()->status)->toBe(RentableItem::STATUS_ASSIGNED);
});

it('offers the freed bay to the next tenant, and says so on the register', function () {
    // The two answers must agree. Before the fix they did not: the bay was offered by the picker
    // (which reads `isHeldOn()`) while the register said `assigned` (which reads the column).
    $lease = makeLease(makeUnit($this->asset), null, [
        'status' => 'active',
        'expiry_date' => CarbonImmutable::now()->subMonth()->toDateString(),
    ]);
    $bay = bayIn($this->asset);

    $this->service->assign($lease, $bay, ['effective_from' => CarbonImmutable::now()->subYear()->toDateString()]);
    $this->artisan('leases:expire')->assertSuccessful();

    $next = makeLease(makeUnit($this->asset), null, ['status' => 'active']);

    expect(RentableItemOptions::lettable($next))->toHaveKey($bay->id)
        ->and($bay->fresh()->status)->toBe(RentableItem::STATUS_AVAILABLE);
});

it('is registered as a projection, with a sweep that is actually scheduled', function () {
    // The registry entry is the part that stops this recurring: `ProjectedStateConformanceTest`
    // requires the projector to exist, the sweep to exist AND to be scheduled, and a second
    // consecutive run to find no work.
    expect(ProjectedState::PROJECTIONS)->toHaveKey('rentable_item.occupancy');

    $entry = ProjectedState::PROJECTIONS['rentable_item.occupancy'];

    expect($entry['model'])->toBe(RentableItem::class)
        ->and($entry['column'])->toBe('status')
        ->and($entry['sweep'])->toBe('leases:expire');
});
