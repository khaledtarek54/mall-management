<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;

/**
 * Automatic rent escalation (competitive gap analysis strengthen item): the escalation fields
 * existed but nothing applied them. This sweep does, idempotently + lock-safe, through
 * LeaseRentChangeService (so the base-rent Charge stays in sync). "Today" is fixed at 2026-07-19.
 */
function escalationLease(array $attrs): Lease
{
    return makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 2000,
    ], $attrs));
}

$today = fn () => CarbonImmutable::create(2026, 7, 19);

it('applies a due fixed-percent escalation and rolls the date forward a year', function () use ($today) {
    $lease = escalationLease([
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 10,
        'next_escalation_date' => '2026-01-01',
    ]);

    $stats = app(RentEscalationService::class)->runForToday($today());

    expect($stats['applied'])->toBe(1);
    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(11000.0)               // 10,000 × 1.10
        ->and($lease->next_escalation_date->toDateString())->toBe('2027-01-01');

    // The base-rent Charge that MonthlyBillingService reads is kept in lock-step.
    $chargeAmount = (float) Charge::where('lease_id', $lease->id)
        ->where('type', 'base_rent')->where('is_active', true)->value('amount');
    expect($chargeAmount)->toBe(11000.0);
});

it('is idempotent — a second run the same day does not re-escalate', function () use ($today) {
    $lease = escalationLease([
        'escalation_type' => 'fixed_percent', 'escalation_rate' => 10, 'next_escalation_date' => '2026-01-01',
    ]);
    $svc = app(RentEscalationService::class);

    $svc->runForToday($today());
    $second = $svc->runForToday($today());

    expect($second['applied'])->toBe(0)
        ->and((float) $lease->refresh()->base_rent_monthly)->toBe(11000.0); // not compounded to 12,100
});

it('skips CPI escalation (no index feed) without changing rent', function () use ($today) {
    $lease = escalationLease([
        'escalation_type' => 'cpi', 'escalation_rate' => 10, 'next_escalation_date' => '2026-01-01',
    ]);

    $stats = app(RentEscalationService::class)->runForToday($today());

    expect($stats['skipped'])->toBe(1)
        ->and($stats['applied'])->toBe(0)
        ->and((float) $lease->refresh()->base_rent_monthly)->toBe(10000.0);
});

it('leaves a not-yet-due lease untouched', function () use ($today) {
    $lease = escalationLease([
        'escalation_type' => 'fixed_percent', 'escalation_rate' => 10, 'next_escalation_date' => '2027-01-01',
    ]);

    $stats = app(RentEscalationService::class)->runForToday($today());

    expect($stats['considered'])->toBe(0)
        ->and((float) $lease->refresh()->base_rent_monthly)->toBe(10000.0);
});

it('rolls the date but does not change rent for a 0% escalation', function () use ($today) {
    $lease = escalationLease([
        'escalation_type' => 'fixed_percent', 'escalation_rate' => 0, 'next_escalation_date' => '2026-01-01',
    ]);

    app(RentEscalationService::class)->runForToday($today());

    $lease->refresh();
    expect((float) $lease->base_rent_monthly)->toBe(10000.0)
        ->and($lease->next_escalation_date->toDateString())->toBe('2027-01-01'); // advanced, so it won't re-consider daily
});
