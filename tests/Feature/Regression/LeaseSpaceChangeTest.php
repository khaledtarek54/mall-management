<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Unit;
use App\Services\LeaseSpaceChangeService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * The premises are date-ranged, like the rent (phase 2, story LE-02 — scenario S5).
 *
 * Expanding used to mean adding a pivot row with no date and typing a blended rent with no date, so
 * the extra area counted from whenever somebody clicked save. Contracting meant DELETING the pivot
 * row, which erased the fact that the tenant ever held the space — so the next recovery
 * reconciliation re-apportioned the whole year as if it had never been theirs.
 *
 * The assertion that matters is the one about time: October must bill and apportion on the OLD
 * footprint even when the change is entered in September, and the released space must still count
 * for the months it was actually held.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function spaceLease(float $rent = 180000, float $masterArea = 900): array
{
    $asset = makeAsset();
    $master = makeUnit($asset, ['area_sqm' => $masterArea]);

    $lease = makeLease($master, null, [
        'status' => 'active',
        'commencement_date' => '2028-01-01',
        'expiry_date' => '2032-12-31',
        'base_rent_monthly' => $rent,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $rent, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2028-01-01', 'is_active' => true,
    ]);

    return [$lease->fresh(), $asset, $master];
}

function baseRentInvoiced(Lease $lease, string $month): float
{
    Invoice::where('lease_id', $lease->id)->delete();

    app(MonthlyBillingService::class)->generateForLease($lease->fresh(), CarbonImmutable::parse($month));

    return (float) Invoice::where('lease_id', $lease->id)
        ->with('items')->get()
        ->flatMap(fn (Invoice $i) => $i->items)
        ->where('type', 'base_rent')->sum('amount');
}

it('moves area and rent on the effective date, not on the day the change was entered', function () {
    // S5: Zara takes the adjacent 300 m² from 1 Nov 2028, rent rises to 235,000. Entered in
    // September — which is normal, and which used to make September's invoice wrong.
    CarbonImmutable::setTestNow('2028-09-20');
    [$lease, $asset] = spaceLease(180000, 900);
    $extra = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-15']);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$extra->id],
        'effective_from' => '2028-11-01',
        'new_total_rent' => 235000,
        'reason' => 'Expansion into A-15 per amendment 4.',
        'document_reference' => 'Amendment 4',
    ]);

    $lease = $lease->fresh();

    expect($lease->totalAreaSqmOn(CarbonImmutable::parse('2028-10-31')))->toBe(900.0)
        ->and($lease->totalAreaSqmOn(CarbonImmutable::parse('2028-11-01')))->toBe(1200.0)
        ->and(baseRentInvoiced($lease, '2028-10-15'))->toBe(180000.0)
        ->and(baseRentInvoiced($lease, '2028-11-15'))->toBe(235000.0);
});

it('apportions CAM on the time-weighted area, so two months of extra space is not a year of it', function () {
    // The money consequence of S5. 900 m² for ten months plus 1,200 m² for two is
    // 900 + 300 × (61/366) ≈ 950 m² of weighted area in 2028 — not 1,200.
    CarbonImmutable::setTestNow('2028-09-20');
    [$lease, $asset] = spaceLease(180000, 900);
    $extra = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-15']);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$extra->id],
        'effective_from' => '2028-11-01',
        'reason' => 'Expansion into A-15.',
    ]);

    $weighted = $lease->fresh()->totalAreaSqmForPeriod(
        CarbonImmutable::parse('2028-01-01'),
        CarbonImmutable::parse('2028-12-31'),
    );

    // 2028 is a leap year: 366 days, and Nov+Dec is 61 of them.
    expect(round($weighted, 2))->toBe(round(900 + 300 * (61 / 366), 2))
        ->and($weighted)->toBeLessThan(1200.0)
        ->and($weighted)->toBeGreaterThan(900.0);
});

it('keeps a given-back unit counting for the months it was actually held', function () {
    // The contraction bug: deleting the pivot row erased the tenancy. Closing it keeps the history,
    // so a reconciliation still charges them for the space they had.
    CarbonImmutable::setTestNow('2028-09-20');
    [$lease, $asset, $master] = spaceLease(180000, 900);
    $extra = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-15']);

    $lease->units()->attach($extra->id, [
        'is_master' => false, 'effective_from' => '2028-01-01', 'effective_to' => null,
    ]);
    $lease->load('units');

    app(LeaseSpaceChangeService::class)->contract($lease, [
        'unit_ids' => [$extra->id],
        'effective_from' => '2028-07-01',
        'new_total_rent' => 180000,
        'reason' => 'A-15 handed back by agreement.',
    ]);

    $lease = $lease->fresh();

    expect($lease->totalAreaSqmOn(CarbonImmutable::parse('2028-06-30')))->toBe(1200.0)
        ->and($lease->totalAreaSqmOn(CarbonImmutable::parse('2028-07-01')))->toBe(900.0)
        // Jan–Jun is 182 of 2028's 366 days — the tenant still carries that share.
        ->and(round($lease->totalAreaSqmForPeriod(
            CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-12-31')
        ), 2))->toBe(round(900 + 300 * (182 / 366), 2));
});

it('frees the released unit for re-letting and marks it vacant', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    [$lease, $asset] = spaceLease(180000, 900);
    $extra = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-15']);

    $lease->units()->attach($extra->id, ['is_master' => false, 'effective_from' => '2028-01-01', 'effective_to' => null]);
    $lease->load('units');
    $extra->recomputeStatus();

    expect($extra->fresh()->isActivelyLeased())->toBeTrue();

    app(LeaseSpaceChangeService::class)->contract($lease, [
        'unit_ids' => [$extra->id], 'effective_from' => '2028-07-01',
        'reason' => 'Handed back.',
    ]);

    // Released space the mall could not re-let would make contraction useless.
    expect($extra->fresh()->isActivelyLeased())->toBeFalse()
        ->and($extra->fresh()->status)->toBe('vacant')
        // …while the lease itself is untouched on its remaining unit.
        ->and($lease->fresh()->status)->toBe('active');
});

it('records expansion and contraction as their own event types, naming the units and the area', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    [$lease, $asset] = spaceLease(180000, 900);
    $extra = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-15']);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$extra->id], 'effective_from' => '2028-11-01',
        'new_total_rent' => 235000,
        'reason' => 'Expansion into A-15 per amendment 4.',
        'document_reference' => 'Amendment 4',
    ]);

    $event = $lease->fresh()->events()->first();

    expect($event->type)->toBe(LeaseEvent::TYPE_EXPANSION)
        ->and($event->effective_date->toDateString())->toBe('2028-11-01')
        ->and($event->document_reference)->toBe('Amendment 4')
        ->and($event->payload['units_added'])->toBe(['A-15'])
        ->and($event->payload['area_from'])->toEqual(900.0)
        ->and($event->payload['area_to'])->toEqual(1200.0)
        ->and($event->payload['amount_from'])->toEqual(180000.0)
        ->and($event->payload['amount_to'])->toEqual(235000.0);
});

it('refuses to expand into a unit let to someone else, or from another property', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    [$lease, $asset] = spaceLease();
    $taken = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-16']);
    $otherLease = makeLease($taken, null, ['status' => 'active', 'commencement_date' => '2028-01-01', 'expiry_date' => '2030-12-31']);

    expect(fn () => app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$taken->id], 'effective_from' => '2028-11-01', 'reason' => 'Double-booking.',
    ]))->toThrow(InvalidArgumentException::class);

    $elsewhere = makeUnit(makeAsset(), ['area_sqm' => 300, 'code' => 'B-01']);

    expect(fn () => app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$elsewhere->id], 'effective_from' => '2028-11-01', 'reason' => 'Wrong mall.',
    ]))->toThrow(InvalidArgumentException::class);

    // The control: a genuinely free unit in the same property DOES expand, so the refusals above
    // are not passing because expansion is broken.
    $free = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-17']);
    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$free->id], 'effective_from' => '2028-11-01', 'reason' => 'Valid.',
    ]);

    expect($lease->fresh()->totalAreaSqmOn(CarbonImmutable::parse('2028-11-01')))->toBe(1200.0);
});

it('refuses to give back the master unit or the whole premises', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    [$lease, $asset, $master] = spaceLease();

    // The master is the lease's identity — leases.unit_id points at it and every single-unit code
    // path reads it. Giving it back is a relocation, a different deal.
    expect(fn () => app(LeaseSpaceChangeService::class)->contract($lease, [
        'unit_ids' => [$master->id], 'effective_from' => '2028-11-01', 'reason' => 'Not a contraction.',
    ]))->toThrow(InvalidArgumentException::class);

    $extra = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-15']);
    $lease->units()->attach($extra->id, ['is_master' => false, 'effective_from' => '2028-01-01', 'effective_to' => null]);
    $lease->load('units');

    // The control: giving back the NON-master unit works.
    app(LeaseSpaceChangeService::class)->contract($lease, [
        'unit_ids' => [$extra->id], 'effective_from' => '2028-11-01', 'reason' => 'Valid.',
    ]);

    expect($lease->fresh()->events()->where('type', LeaseEvent::TYPE_CONTRACTION)->count())->toBe(1);
});

it('reserves a unit an expansion has already claimed for a future date', function () {
    // Found by the amendment scenario test. Making the pivot date-ranged meant "is this unit
    // leased" and "is this unit occupied" stopped being the same question — and the double-booking
    // guard was reading the occupancy one. An expansion agreed in September to take effect on
    // 1 November left A-15 reading as VACANT and freely bookable through October, so a second
    // lease could take it and collide with the expansion on the day it landed.
    CarbonImmutable::setTestNow('2028-09-20');
    [$lease, $asset] = spaceLease(180000, 900);
    $extra = makeUnit($asset, ['area_sqm' => 300, 'code' => 'A-15']);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$extra->id], 'effective_from' => '2028-11-01',
        'reason' => 'Expansion from November.',
    ]);

    // Not occupied yet — nobody is in it until November — but not free either.
    expect($extra->fresh()->status)->toBe('reserved')
        ->and($extra->fresh()->isActivelyLeased())->toBeTrue();

    // …and once November arrives it is simply occupied.
    CarbonImmutable::setTestNow('2028-11-05');
    $extra->fresh()->recomputeStatus();

    expect($extra->fresh()->status)->toBe('occupied');

    // The control, and the other half of the rule: space genuinely HANDED BACK does free up.
    CarbonImmutable::setTestNow('2029-01-10');
    app(LeaseSpaceChangeService::class)->contract($lease->fresh(), [
        'unit_ids' => [$extra->id], 'effective_from' => '2029-02-01',
        'reason' => 'Handed back.',
    ]);

    CarbonImmutable::setTestNow('2029-02-10');
    $extra->fresh()->recomputeStatus();

    expect($extra->fresh()->isActivelyLeased())->toBeFalse()
        ->and($extra->fresh()->status)->toBe('vacant');
});

it('leaves the area basis unchanged for every lease whose premises carry no dates', function () {
    // The safety property of the whole change: an existing lease has NULL on both pivot sides, so
    // the time-weighted basis must equal the flat total exactly. If this drifts, every CAM pool in
    // the system re-bases silently.
    [$lease] = spaceLease(180000, 900);

    expect($lease->totalAreaSqmForPeriod(
        CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-12-31')
    ))->toBe(900.0)
        ->and($lease->totalAreaSqm())->toBe(900.0);
});
