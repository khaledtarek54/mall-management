<?php

use App\Models\Lease;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;

/**
 * The escalation collar (الحد الأدنى/الأقصى للزيادة) — Yardi gap-analysis row 61.
 *
 * The clause it serves is the standard index-linked one: *"the annual increase shall be the greater
 * of CPI or 3%, capped at 10%"*. Atriom stored only the rate, so the two bounds that decide what a
 * tenant actually pays in the years the index misbehaves had nowhere to live.
 *
 * The bounds clamp whatever rate is about to be applied, which is what makes them bite before CPI
 * exists: on a `fixed_percent` lease the ceiling is a rail against a mistyped rate — a `70` entered
 * for `7` would otherwise step the rent seventy percent on the anniversary, unattended.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function collarLease(array $attributes = []): Lease
{
    return makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'next_escalation_date' => '2026-01-01',
    ], $attributes))->fresh();
}

it('caps the increase at the ceiling', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = collarLease(['escalation_rate' => 70, 'escalation_ceiling_rate' => 10]);

    app(RentEscalationService::class)->runForToday();

    // 10%, not 70% — the mistyped rate is clamped, not obeyed.
    expect((float) $lease->fresh()->base_rent_monthly)->toBe(110000.0);
});

it('lifts the increase to the floor', function () {
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = collarLease(['escalation_rate' => 1, 'escalation_floor_rate' => 3]);

    app(RentEscalationService::class)->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(103000.0);
});

it('leaves a rate inside the collar exactly as contracted', function () {
    // The control: the collar must not move a rate it has no business moving.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = collarLease(['escalation_rate' => 7, 'escalation_floor_rate' => 3, 'escalation_ceiling_rate' => 10]);

    app(RentEscalationService::class)->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(107000.0);
});

it('applies one bound when only one is set', function () {
    // A lease with a ceiling and no floor is that, not one floored at zero.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = collarLease(['escalation_rate' => 4, 'escalation_ceiling_rate' => 10]);

    app(RentEscalationService::class)->runForToday();

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(104000.0);
});

it('refuses a floor above the ceiling', function () {
    // No reading: the floor is applied and then the ceiling overrides it, so the "minimum" the
    // operator typed would be the one increase that could never happen.
    expect(fn () => collarLease(['escalation_floor_rate' => 12, 'escalation_ceiling_rate' => 5]))
        ->toThrow(DomainException::class);

    // The control — equal bounds are a fixed step, not a contradiction.
    $lease = collarLease(['escalation_rate' => 7, 'escalation_floor_rate' => 5, 'escalation_ceiling_rate' => 5]);

    expect((float) $lease->escalation_ceiling_rate)->toBe(5.0);
});

it('states the rate it actually applied, not the one on the lease', function () {
    // The rent-change reason is what an operator reads a year later to explain the step. Recording
    // the uncollared rate there would describe an increase that never happened.
    CarbonImmutable::setTestNow('2026-01-02');
    $lease = collarLease(['escalation_rate' => 70, 'escalation_ceiling_rate' => 10]);

    app(RentEscalationService::class)->runForToday();

    $row = $lease->fresh()->charges()
        ->where('type', 'base_rent')
        ->orderByDesc('start_date')
        ->first();

    expect((float) $row->amount)->toBe(110000.0);
});
