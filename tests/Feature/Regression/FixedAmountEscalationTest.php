<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;

/**
 * Escalation by a fixed AMOUNT — the last open half of Yardi gap-analysis row 61.
 *
 * Yardi stores an escalation as a percentage, a fixed amount, or an index. Atriom had the percentage
 * and a deliberately-unimplemented index (CPI is skipped — inventing an index number would be
 * inventing data). The amount was missing, and it is the one of the three needing no external feed:
 * *"rent increases by EGP 5,000 per month each year"* is an ordinary anchor-tenant term that could
 * previously only be honoured by an operator remembering to raise the rent by hand every
 * anniversary — the exact revenue leak the sweep exists to close for percentage leases.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function amountLease(array $attributes = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
        'escalation_type' => 'fixed_amount',
        'escalation_amount' => 5000,
        'escalation_rate' => 0,
        'next_escalation_date' => '2026-01-01',
    ], $attributes));

    // The rent row the lease is actually billing under. Without it the escalation opens the FIRST
    // schedule row, which `ChargeScheduleService` dates to the lease commencement rather than the
    // anniversary — correct behaviour, but it would make the dating assertion below test the
    // fixture instead of the feature.
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

it('adds the stated amount rather than a percentage', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = amountLease();

    app(RentEscalationService::class)->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(105000.0);
});

it('dates the step to the anniversary, not the night the sweep ran', function () {
    // The same contract the percentage path keeps: a sweep delayed by a weekend must not move the
    // increase. The schedule row is what proves it, not the lease column.
    CarbonImmutable::setTestNow('2026-01-09'); // a week late
    $lease = amountLease();

    app(RentEscalationService::class)->runForToday();

    $row = $lease->fresh()->charges()
        ->where('type', 'base_rent')
        ->orderByDesc('start_date')
        ->first();

    expect($row->start_date->toDateString())->toBe('2026-01-01')
        ->and((float) $row->amount)->toBe(105000.0);
});

it('rolls the anniversary forward and does not re-apply on a re-run', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = amountLease();
    $service = app(RentEscalationService::class);

    $service->runForToday();
    $service->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(105000.0)
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01');
});

it('compounds on the new rent the following year, like the percentage path', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = amountLease();
    $service = app(RentEscalationService::class);

    $service->runForToday();

    CarbonImmutable::setTestNow('2027-01-02');
    $service->runForToday();

    // A flat amount does not compound as a rate does — it is +5,000 twice, not +5,000 then +5,250.
    expect((float) $lease->fresh()->base_rent_monthly)->toBe(110000.0);
});

it('skips a lease whose amount is zero, and still rolls the date', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = amountLease(['escalation_amount' => 0]);

    app(RentEscalationService::class)->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(100000.0)
        // Rolled anyway, or the sweep would reconsider it every single day forever.
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01');
});

it('never reads the percentage collar for an amount lease', function () {
    // The collar is expressed in PERCENT. Applying a floor of "3" to a step stated in pounds would
    // silently reinterpret one unit as the other and escalate by something nobody agreed.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = amountLease([
        'escalation_amount' => 5000,
        'escalation_floor_rate' => 10,
        'escalation_ceiling_rate' => 20,
    ]);

    app(RentEscalationService::class)->runForToday();

    // +5,000, not +10% (110,000) and not +20% (120,000).
    expect((float) $lease->fresh()->base_rent_monthly)->toBe(105000.0);
});

it('arms the anniversary on create for an amount lease', function () {
    // The hook used to key on `escalation_rate > 0`, which is zero on an amount lease — so without
    // this an amount escalation would never have been swept at all. That is the same dead-feature
    // shape the percentage path was fixed for once already.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
        'escalation_type' => 'fixed_amount',
        'escalation_amount' => 5000,
        'escalation_rate' => 0,
    ]);

    expect($lease->next_escalation_date?->toDateString())->toBe('2027-01-01');
});
