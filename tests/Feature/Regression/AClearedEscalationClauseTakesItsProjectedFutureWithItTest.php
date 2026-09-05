<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\LeaseRentChangeService;
use Carbon\CarbonImmutable;

/**
 * Clearing an escalation clause PRUNES its projected future (2026-09-05).
 *
 * The whole term's ladder is written at signing (LS-01), and `Lease::saving` clears the clause's
 * COLUMNS when it is switched off — but nothing cleared the schedule: the projected rungs kept
 * billing increases for a clause the operator had removed, and the sweep, which corrects a wrong
 * rung in place each anniversary, never runs for a cleared clause (`none` is outside its
 * `whereIn`; a cleared service-charge toggle fails the predicate). So the two clearing events —
 * `escalation_type` → `none`, and the service-charge toggle → off — are exactly the cases where
 * the self-correction mechanism dies, and the ones `pruneProjectedLadder()` handles.
 *
 * The dangerous half is not the deactivation, it is the RE-OPENING: the projection closed each
 * rung the day before the next, so deactivating the future without re-opening the survivor stops
 * the charge billing entirely at the next anniversary — worse than the escalated amount.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function clauseClearingLease(array $attributes = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 20000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'escalation_applies_to_service_charge' => true,
        'next_escalation_date' => '2026-01-01',
    ], $attributes));

    foreach ([
        ['base_rent', 'Base Rent', $lease->base_rent_monthly, Charge::ORIGIN_SEED],
        ['service_charge', 'Service Charge', $lease->service_charge_monthly, Charge::ORIGIN_SEED],
        ['marketing', 'Marketing Levy', 5000, Charge::ORIGIN_LEVY],
    ] as [$type, $name, $amount, $origin]) {
        Charge::create([
            'lease_id' => $lease->id,
            'name' => $name,
            'type' => $type,
            'origin' => $origin,
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

it('turning the service-charge toggle off deactivates its future rungs and re-opens the survivor', function () {
    CarbonImmutable::setTestNow('2025-06-01'); // before the first anniversary — every rung is future
    $lease = clauseClearingLease();

    expect($lease->charges()->where('type', 'service_charge')->where('is_active', true)->count())->toBe(4);

    $lease->update(['escalation_applies_to_service_charge' => false]);

    $active = $lease->charges()->where('type', 'service_charge')->where('is_active', true)->get();

    // One surviving row, RE-OPENED: without clearing the end the projection stamped on it, the
    // service charge stops billing entirely at the first anniversary.
    expect($active)->toHaveCount(1)
        ->and((float) $active->first()->amount)->toBe(20000.0)
        ->and($active->first()->end_date)->toBeNull()
        // The rent ladder is untouched — the toggle covers the service charge alone.
        ->and($lease->charges()->where('type', 'base_rent')->where('is_active', true)->count())->toBe(4);
});

it('switching the clause to none prunes rent, service and lock-step levy futures, keeping what already billed', function () {
    // Mid-term: the 2026 rung has started — it is the current amount and the history it made, so
    // it stays; 2027 and 2028 are the future the cleared clause no longer owns.
    CarbonImmutable::setTestNow('2026-06-01');
    $lease = clauseClearingLease();

    $lease->update(['escalation_type' => 'none']);

    foreach (['base_rent' => 107000.0, 'service_charge' => 21400.0] as $type => $current) {
        $active = $lease->charges()->where('type', $type)->where('is_active', true)
            ->orderBy('start_date')->get();

        expect($active)->toHaveCount(2)
            ->and((float) $active->last()->amount)->toBe($current)
            ->and($active->last()->start_date->toDateString())->toBe('2026-01-01')
            // Re-opened: the 2026 rung was closed the day before the pruned 2027 rung.
            ->and($active->last()->end_date)->toBeNull();
    }

    // The levy's projected lock-step rungs went with the rent rungs they were derived from.
    $levy = $lease->charges()->where('type', 'marketing')->where('is_active', true)
        ->orderBy('start_date')->get();

    expect($levy)->toHaveCount(2)
        ->and($levy->last()->end_date)->toBeNull();
});

it('a future rung the operator stated through Change Rent survives the prune, and the chain re-links around it', function () {
    CarbonImmutable::setTestNow('2025-06-01');
    $lease = clauseClearingLease();

    // The realistic manual-future shape: Change Rent effective a future anniversary AMENDS the
    // not-yet-started projected rung in place and flips its origin to manual — a stated term.
    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 120000,
        'reason' => 'Negotiated step',
        'effective_from' => '2027-01-01',
    ]);

    $lease->refresh()->update(['escalation_type' => 'none']);

    $active = $lease->charges()->where('type', 'base_rent')->where('is_active', true)
        ->orderBy('start_date')->get();

    // Original row + the stated 120,000 rung; the projected 2026 and 2028 rungs are gone.
    expect($active->map(fn (Charge $c) => (float) $c->amount)->all())->toBe([100000.0, 120000.0])
        // The survivor BEFORE the hole extends to the stated rung's eve — not past it.
        ->and($active->first()->end_date->toDateString())->toBe('2026-12-31')
        // The stated rung itself re-opens: its old end abutted the pruned 2028 rung.
        ->and($active->last()->end_date)->toBeNull();
});
