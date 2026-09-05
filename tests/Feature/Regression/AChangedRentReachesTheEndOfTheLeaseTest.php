<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\LeaseRentChangeService;
use App\Services\LeaseSpaceChangeService;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;

/**
 * A CHANGED RENT REACHES THE END OF THE LEASE — the ladder follows the change (2026-09-05).
 *
 * Reported from the panel, verbatim: *"I tried the change rent and it applied for a year only …
 * and it doesn't apply until the end of the lease."* The operator was right. On a lease with a
 * projected escalation ladder (LS-01 — the normal case for every fixed-percent lease), Change
 * Rent's new row inherits its end from the row it closes — the eve of the next anniversary — and
 * every rung beyond it was computed from the OLD rent at signing. So the change visibly died
 * after one year: the charge schedule, the billing forecast and the rent roll all reverted to
 * old-rent figures, and only the sweep's night-of-the-anniversary amend would quietly correct
 * each rung as it arrived — or fail to, if the sweep were ever down.
 *
 * `LeaseRentChangeService::apply()` and `LeaseSpaceChangeService` now re-true the ladder through
 * the same `projectTermEscalations()` walk, which re-derives each future escalation rung from the
 * rent in force on its own eve. A rung the operator STATED (a future-dated Change Rent amends a
 * rung in place and marks it `manual`) survives and resets the compounding — the contract's own
 * reading: escalation applies to the rent in force.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function retruedLadderLease(array $attributes = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 20000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'next_escalation_date' => '2026-01-01',
    ], $attributes));

    foreach ([
        ['base_rent', 'Base Rent', $lease->base_rent_monthly],
        ['service_charge', 'Service Charge', $lease->service_charge_monthly],
    ] as [$type, $name, $amount]) {
        if ((float) $amount <= 0) {
            continue;
        }

        Charge::create([
            'lease_id' => $lease->id,
            'name' => $name,
            'type' => $type,
            'origin' => Charge::ORIGIN_SEED,
            'amount' => $amount,
            'currency' => 'EGP',
            'frequency' => 'monthly',
            'start_date' => $lease->commencement_date,
            'is_active' => true,
        ]);
    }

    app(ChargeScheduleService::class)->projectTermEscalations($lease->fresh());

    return $lease->fresh();
}

function activeRentAmounts(Lease $lease, string $type = 'base_rent'): array
{
    return $lease->charges()->where('type', $type)->where('is_active', true)
        ->orderBy('start_date')->pluck('amount')->map(fn ($a) => (float) $a)->all();
}

it('applies a rent change to the END of the lease, restating every future rung', function () {
    // The report, exactly: ladder projected at signing (107,000 / 114,490 / 122,504.30), rent
    // changed mid-term to 120,000. Before the fix the schedule read 120,000 for one year and then
    // reverted to the old-rent ladder.
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = retruedLadderLease();

    expect(activeRentAmounts($lease))->toBe([100000.0, 107000.0, 114490.0, 122504.30]);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 120000,
        'reason' => 'Renegotiated',
    ]);

    expect(activeRentAmounts($lease))->toBe([100000.0, 120000.0, 128400.0, 137388.0, 147005.16]);

    // "It doesn't apply until the end of the lease" — now it does: the last rung is open-ended.
    $last = $lease->charges()->where('type', 'base_rent')->where('is_active', true)
        ->orderByDesc('start_date')->first();

    expect($last->end_date)->toBeNull();
});

it('re-trues a rent DECREASE the same way — a renegotiation, not a relief', function () {
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = retruedLadderLease();

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 80000,
        'reason' => 'Renegotiated down',
    ]);

    expect(activeRentAmounts($lease))->toBe([100000.0, 80000.0, 85600.0, 91592.0, 98003.44]);
});

it('a stated future rung survives the re-true and resets the compounding', function () {
    // First act: a future-dated Change Rent states 150,000 from the second anniversary — that
    // amends the projected rung in place and marks it manual. Second act: an ordinary change to
    // 120,000 today. The ladder must read 120,000 → 128,400 → the STATED 150,000 → 160,500:
    // arithmetic never overwrites a negotiated term, and the step after it compounds from it.
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = retruedLadderLease();

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 150000,
        'reason' => 'Agreed step',
        'effective_from' => '2027-01-01',
    ]);

    app(LeaseRentChangeService::class)->apply($lease->fresh(), [
        'base_rent_monthly' => 120000,
        'reason' => 'Renegotiated',
        'effective_from' => '2025-06-01',
    ]);

    expect(activeRentAmounts($lease))->toBe([100000.0, 120000.0, 128400.0, 150000.0, 160500.0]);
});

it('re-trues a fixed-AMOUNT ladder too', function () {
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = retruedLadderLease([
        'escalation_type' => 'fixed_amount',
        'escalation_rate' => 0,
        'escalation_amount' => 5000,
    ]);

    expect(activeRentAmounts($lease))->toBe([100000.0, 105000.0, 110000.0, 115000.0]);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 120000,
        'reason' => 'Renegotiated',
    ]);

    expect(activeRentAmounts($lease))->toBe([100000.0, 120000.0, 125000.0, 130000.0, 135000.0]);
});

it('re-trues the service-charge ladder when the change moves the service charge too', function () {
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = retruedLadderLease(['escalation_applies_to_service_charge' => true]);

    expect(activeRentAmounts($lease, 'service_charge'))->toBe([20000.0, 21400.0, 22898.0, 24500.86]);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 120000,
        'service_charge_monthly' => 25000,
        'reason' => 'Renegotiated',
    ]);

    expect(activeRentAmounts($lease, 'service_charge'))->toBe([20000.0, 25000.0, 26750.0, 28622.50, 30626.08]);
});

it('mints no ladder on a lease that has none — CPI stays unprojected', function () {
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = retruedLadderLease([
        'escalation_type' => 'cpi',
        'escalation_rate' => 0,
        'escalation_index_code' => 'CPI-EG',
        'escalation_index_base_value' => 100,
    ]);

    // CPI is never projected — no index feed — so the fixture starts with the seed row alone.
    expect(activeRentAmounts($lease))->toBe([100000.0]);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 120000,
        'reason' => 'Renegotiated',
    ]);

    expect(activeRentAmounts($lease))->toBe([100000.0, 120000.0]);
});

it('still converges with the sweep after a change — the anniversary amends nothing twice', function () {
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = retruedLadderLease();

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 120000,
        'reason' => 'Renegotiated',
    ]);

    CarbonImmutable::setTestNow('2026-01-02');
    $stats = app(RentEscalationService::class)->runForToday();

    $lease->refresh();
    expect($stats['failed'])->toBe(0)
        ->and((float) $lease->base_rent_monthly)->toBe(128400.0)
        ->and(activeRentAmounts($lease))->toBe([100000.0, 120000.0, 128400.0, 137388.0, 147005.16]);
});

it('an expansion reaches the end of the lease too', function () {
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = retruedLadderLease();
    $extra = makeUnit($lease->unit->asset);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$extra->id],
        'effective_from' => '2025-06-01',
        'new_total_rent' => 130000,
        'reason' => 'Took the unit next door',
    ]);

    expect(activeRentAmounts($lease->fresh()))->toBe([100000.0, 130000.0, 139100.0, 148837.0, 159255.59]);
});
