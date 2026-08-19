<?php

/*
|--------------------------------------------------------------------------
| An owner-occupier could not hold a parking bay (2026-08-19)
|--------------------------------------------------------------------------
| `lease_rentable_item` was keyed to a lease, and a unit owner holds none — so the buyer who trades
| from his own shop could not rent the bay outside it.
|
| **This is Voyager's model read correctly, not an extension of it.** Rentable items are assigned to
| the customer RECORD (`docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2 — "assign Rentable
| Items and Service Charges to both new and existing residents"), and in Voyager Condo/Co-Op the
| unit owner IS that record, with dues posting to his ledger. Atriom had narrowed "customer record"
| to "lease" only because, when rentable items were built, a lease was the only agreement that
| existed. Operator's decision (2026-08-19): the bay charge rides the monthly صيانة assessment.
|
| **Not the lease-shaped-assumption class module 37 wrote up.** Those inferred a lease where the
| money core already accepted a `BillableAgreement`, so the fix was a lookup. Here the RELATIONSHIP
| itself was lease-shaped, which is a migration and a backfill.
|
| The guard most at risk in this refactor is the double-let: `isHeldOn()` asked only about leases,
| which was correct while a lease was the only holder and becomes a real double-booking the moment
| an ownership can hold one. That is asserted in both directions below.
*/

use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\RelationManagers\UnitOwnershipRentableItemsRelationManager;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\EditUnitOwnership;
use App\Models\RentableItem;
use App\Models\UnitOwnership;
use App\Services\AssignRentableItemService;
use App\Support\RentableItemOptions;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
});

function ownedUnit($ctx, string $status = 'handed_over'): UnitOwnership
{
    return UnitOwnership::create([
        'asset_id' => $ctx->asset->id,
        'unit_id' => $ctx->unit->id,
        'tenant_id' => makeTenant()->id,
        'reference' => 'OWN-'.uniqid(),
        'status' => $status,
        'tenure_type' => 'freehold',
        'management_mode' => 'self_occupied',
        'started_at' => '2026-01-01',
    ]);
}

function bay($ctx, array $attrs = []): RentableItem
{
    return RentableItem::create(array_merge([
        'asset_id' => $ctx->asset->id,
        'code' => 'P-'.uniqid(),
        'type' => 'parking',
        'status' => RentableItem::STATUS_AVAILABLE,
        'monthly_rate' => 500,
    ], $attrs));
}

it('lets an owner hold a bay and bills it on his own schedule', function () {
    $ownership = ownedUnit($this);
    $item = bay($this);

    app(AssignRentableItemService::class)->assign($ownership, $item, [
        'effective_from' => '2026-03-01',
        'monthly_rate' => 750,
    ]);

    expect($ownership->fresh()->rentableItems()->count())->toBe(1);

    // The bay bills as an ordinary recurring charge on the AGREEMENT's schedule — Voyager's
    // "enter it under the lease charges column", where for an owner the schedule is his assessment.
    $charge = $ownership->fresh()->charges()->where('type', 'parking')->first();

    expect($charge)->not->toBeNull()
        ->and((float) $charge->amount)->toBe(750.0);
});

/**
 * The double-let guard, in the direction that did not exist before this change. A bay held by an
 * owner must look TAKEN to a lease — otherwise the same space is sold twice, which is the exact
 * failure `isHeldOn()` exists to prevent and the one this refactor could most easily have broken.
 */
it('refuses to let a lease take a bay an owner already holds', function () {
    $ownership = ownedUnit($this);
    $item = bay($this);

    app(AssignRentableItemService::class)->assign($ownership, $item, ['effective_from' => '2026-03-01']);

    $lease = makeLease(makeUnit($this->asset));

    expect(fn () => app(AssignRentableItemService::class)
        ->assign($lease, $item, ['effective_from' => '2026-04-01']))
        ->toThrow(DomainException::class);
});

/** And the mirror: a bay a tenant holds is not available to an owner either. */
it('refuses to let an owner take a bay a lease already holds', function () {
    $lease = makeLease(makeUnit($this->asset));
    $item = bay($this);

    app(AssignRentableItemService::class)->assign($lease, $item, ['effective_from' => '2026-03-01']);

    $ownership = ownedUnit($this);

    expect(fn () => app(AssignRentableItemService::class)
        ->assign($ownership, $item, ['effective_from' => '2026-04-01']))
        ->toThrow(DomainException::class);
});

/**
 * The control for both refusals. A guard that refused everything would satisfy them equally, so a
 * genuinely free bay must still be assignable.
 */
it('lets an owner take a bay nobody holds', function () {
    $ownership = ownedUnit($this);

    app(AssignRentableItemService::class)->assign($ownership, bay($this), ['effective_from' => '2026-03-01']);

    expect($ownership->fresh()->rentableItems()->count())->toBe(1);
});

it('releases an owner bay and closes the charge', function () {
    $ownership = ownedUnit($this);
    $item = bay($this);

    $service = app(AssignRentableItemService::class);
    $service->assign($ownership, $item, ['effective_from' => '2026-03-01', 'monthly_rate' => 750]);
    $service->release($ownership, $item, '2026-06-30');

    // Nothing held any more → the charge row CLOSES rather than sitting at zero. A charge for
    // nothing would otherwise print "Parking & rentable items — EGP 0.00" on every assessment for
    // the rest of the tenure.
    $charge = $ownership->fresh()->charges()->where('type', 'parking')->where('is_active', true)->first();

    expect($charge)->toBeNull()
        // And the bay goes back on the market.
        ->and($item->fresh()->isHeldOn(CarbonImmutable::parse('2026-07-01')))->toBeFalse();
});

/** A bay in another mall is not this owner's to take — the property check runs off the contract. */
it('refuses a bay belonging to another property', function () {
    $ownership = ownedUnit($this);
    $elsewhere = bay($this, ['asset_id' => makeAsset()->id]);

    expect(fn () => app(AssignRentableItemService::class)
        ->assign($ownership, $elsewhere, ['effective_from' => '2026-03-01']))
        ->toThrow(DomainException::class);
});

/**
 * A sold-on unit's former owner holds nothing. `transferred` is the ownership's terminal state, and
 * the holder-liveness rule is asked per agreement type because "live" means different things: a
 * lease must be active or pending, an ownership must simply not have been transferred on.
 */
it('refuses to assign to a transferred ownership', function () {
    $ownership = ownedUnit($this);
    $ownership->forceFill(['status' => UnitOwnershipStatus::Transferred->value])->saveQuietly();

    expect(fn () => app(AssignRentableItemService::class)
        ->assign($ownership->fresh(), bay($this), ['effective_from' => '2026-03-01']))
        ->toThrow(DomainException::class);
});

/**
 * A `contracted` owner CAN take a bay before handover, deliberately: the bay is part of what he is
 * buying, and `isBillable()` (handover) governs when it starts being CHARGED — not when it can be
 * recorded.
 */
it('lets a contracted owner take a bay before handover', function () {
    $ownership = ownedUnit($this, status: 'contracted');

    app(AssignRentableItemService::class)->assign($ownership, bay($this), ['effective_from' => '2026-03-01']);

    expect($ownership->fresh()->rentableItems()->count())->toBe(1);
});

/**
 * The shared picker must hide what is already taken, whoever holds it — this is the list an
 * operator actually chooses from, and it is where a stale copy would show a bay that the service
 * then refuses. One answer, whichever surface asks.
 */
it('offers only free bays, across both kinds of holder', function () {
    $ownership = ownedUnit($this);
    $taken = bay($this, ['code' => 'P-TAKEN']);
    $free = bay($this, ['code' => 'P-FREE']);

    $lease = makeLease(makeUnit($this->asset));
    app(AssignRentableItemService::class)->assign($lease, $taken, ['effective_from' => '2026-03-01']);

    $options = RentableItemOptions::lettable($ownership->fresh());

    expect($options)->toHaveKey($free->id)
        ->and($options)->not->toHaveKey($taken->id);
});

/** Existing lease holdings survived the pivot's move to a polymorphic holder. */
it('keeps a lease holding working exactly as before', function () {
    $lease = makeLease(makeUnit($this->asset));
    $item = bay($this);

    app(AssignRentableItemService::class)->assign($lease, $item, [
        'effective_from' => '2026-03-01',
        'monthly_rate' => 600,
    ]);

    $charge = $lease->fresh()->charges()->where('type', 'parking')->first();

    expect($lease->fresh()->rentableItems()->count())->toBe(1)
        ->and((float) $charge->amount)->toBe(600.0);
});

/**
 * The surface, driven rather than asserted about. This project's recurring failure is a capability
 * with no way to reach it — four fully-built, fully-tested services were found unusable in one
 * sweep — so the tab is exercised through Livewire, not merely registered on the resource.
 */
it('shows the bay on the owner\'s own page', function () {
    $ownership = ownedUnit($this);
    $item = bay($this);

    app(AssignRentableItemService::class)->assign($ownership, $item, ['effective_from' => '2026-03-01']);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    Livewire::test(UnitOwnershipRentableItemsRelationManager::class, [
        'ownerRecord' => $ownership->fresh(),
        'pageClass' => EditUnitOwnership::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$item]);
});

/** Assigning from the SCREEN, one click, with the charge following — the claim the design rests on. */
it('assigns a bay from the owner page and the charge follows', function () {
    $ownership = ownedUnit($this);
    $item = bay($this);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    Livewire::test(UnitOwnershipRentableItemsRelationManager::class, [
        'ownerRecord' => $ownership,
        'pageClass' => EditUnitOwnership::class,
    ])
        // `callTableAction`, not `callAction`: this is a TABLE header action on a relation
        // manager, and the two testing helpers look in different places.
        ->callTableAction('assignRentableItem', data: [
            'rentable_item_id' => $item->id,
            'effective_from' => '2026-03-01',
            'monthly_rate' => 400,
        ]);

    $charge = $ownership->fresh()->charges()->where('type', 'parking')->first();

    expect($charge)->not->toBeNull()
        ->and((float) $charge->amount)->toBe(400.0);
});
