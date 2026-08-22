<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;

/**
 * **A rent step is not always annual.** — EG-30 (M-6)
 *
 * `RentEscalationService` rolled `next_escalation_date` with a literal `->addYear()` and no column
 * existed to say otherwise, so a biennial clause, an 18-month step, or the six-monthly review that
 * goes into a short fit-out lease could not be automated at all. What an operator does instead is
 * switch escalation off and do it by hand — which is how a step comes to be missed for a year, the
 * exact revenue leak the sweep exists to close.
 *
 * `leases.escalation_interval_months` is nullable and **null means twelve**, so every lease that
 * existed before this keeps escalating annually and the sweep is behaviour-identical on deploy. The
 * floor lives on `Lease::escalationIntervalMonths()` rather than in the service, because the sweep
 * will not be the only thing that needs to know when the next step falls.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function intervalLease(array $attributes = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2032-12-31',
        'base_rent_monthly' => 100000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 10,
        'next_escalation_date' => '2026-01-01',
    ], $attributes));

    // The rent row the lease bills under — without it the step opens the FIRST schedule row, which
    // is dated to commencement rather than the anniversary, and the dating assertions below would
    // be testing the fixture.
    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => $lease->base_rent_monthly,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => $lease->commencement_date,
        'is_active' => true,
    ]);

    return $lease->fresh();
}

it('still steps annually when the clause says nothing', function () {
    // The control, and the whole deployment argument: null is the state every existing lease is in.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = intervalLease();

    expect($lease->escalation_interval_months)->toBeNull()
        ->and($lease->escalationIntervalMonths())->toBe(12);

    app(RentEscalationService::class)->runForToday();

    expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01');
});

it('rolls a biennial clause two years, not one', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = intervalLease(['escalation_interval_months' => 24]);

    app(RentEscalationService::class)->runForToday();
    $lease = $lease->fresh();

    expect((float) $lease->base_rent_monthly)->toBe(110000.0)
        ->and($lease->next_escalation_date->toDateString())->toBe('2028-01-01');

    // …and does NOT step again on the year in between, which is the half a wrong interval gets
    // wrong silently: the rent would simply be too high, and no error would say so.
    CarbonImmutable::setTestNow('2027-01-02');
    app(RentEscalationService::class)->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(110000.0);
});

it('steps a six-monthly review twice in one year', function () {
    // The opposite direction, and the one an annual-only sweep under-charged for: a short fit-out
    // lease reviewed every six months got ONE step where the clause states two.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = intervalLease(['escalation_interval_months' => 6, 'escalation_rate' => 5]);

    app(RentEscalationService::class)->runForToday();

    expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2026-07-01');

    CarbonImmutable::setTestNow('2026-07-02');
    app(RentEscalationService::class)->runForToday();
    $lease = $lease->fresh();

    // 100,000 → 105,000 → 110,250. Compounding on the CURRENT rent, the reading this codebase
    // already settled for annual steps; an interval must not quietly introduce a second convention.
    expect((float) $lease->base_rent_monthly)->toBe(110250.0)
        ->and($lease->next_escalation_date->toDateString())->toBe('2027-01-01');
});

it('clamps a month-end step to a day the target month has', function () {
    // 31 August + 18 months is 28 February, because February has no 31st. Carbon clamps, which is
    // the same reading `BillingDay` takes of a month-end billing day — and the only one that does
    // not skip the step outright.
    CarbonImmutable::setTestNow('2026-08-31');
    $lease = intervalLease([
        'commencement_date' => '2025-08-31',
        'next_escalation_date' => '2026-08-31',
        'escalation_interval_months' => 18,
    ]);

    app(RentEscalationService::class)->runForToday();

    expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2028-02-29');
});

it('treats a zero interval as one month rather than rolling nowhere', function () {
    // An importer can write 0 where the column allows it, and rolling the date nowhere would make
    // the sweep reconsider the same lease every single day for ever — an infinite no-op with
    // nothing on screen to say so. The floor is one month, the shortest interval that is a real
    // clause. Asserted on the model, because that is where the rule lives.
    $lease = intervalLease(['escalation_interval_months' => 0]);

    expect($lease->escalationIntervalMonths())->toBe(1);

    CarbonImmutable::setTestNow('2026-01-02');
    app(RentEscalationService::class)->runForToday();

    expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2026-02-01');
});
