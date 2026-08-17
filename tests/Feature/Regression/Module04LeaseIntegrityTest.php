<?php

use App\Models\Lease;
use App\Services\LeaseRenewalService;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;

/**
 * Module-04 close-out. Two confirmed bugs from the (business-logic-led) sweep:
 *   1. Automated rent escalation was DEAD in production — no creation path seeded next_escalation_date,
 *      so the daily sweep (whereNotNull) never matched a real lease; the old tests only passed because
 *      fixtures hand-injected the column. Now a Lease::creating hook arms it, so these tests drive the
 *      REAL creation path (escalation config in, no next_escalation_date) and assert it fires.
 *   2. Terminal leases were editable — the model now refuses mutation once terminal.
 */

// ── Rent escalation actually fires ──────────────────────────────────────────────────────────

it('arms next_escalation_date on create when escalation is configured (real path, not injected)', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => '2026-01-01',
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        // next_escalation_date deliberately NOT set — the creating hook must derive it
    ]);

    expect($lease->next_escalation_date?->toDateString())->toBe('2027-01-01');
});

it('does not arm escalation for a none-type or zero-rate lease', function () {
    $none = makeLease(makeUnit(makeAsset()), makeTenant(), ['escalation_type' => 'none', 'escalation_rate' => 0]);
    $zero = makeLease(makeUnit(makeAsset()), makeTenant(), ['escalation_type' => 'fixed_percent', 'escalation_rate' => 0]);

    expect($none->next_escalation_date)->toBeNull()
        ->and($zero->next_escalation_date)->toBeNull();
});

it('actually escalates a real-path lease at its anniversary (end-to-end sweep)', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => CarbonImmutable::now()->subYear()->toDateString(), // anniversary = today
        'base_rent_monthly' => 100000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
    ]);
    expect($lease->next_escalation_date)->not->toBeNull();

    $stats = app(RentEscalationService::class)->runForToday(CarbonImmutable::now()->startOfDay());

    expect($stats['applied'])->toBe(1)
        ->and((float) $lease->fresh()->base_rent_monthly)->toBe(107000.0)
        ->and($lease->fresh()->next_escalation_date->toDateString())
        ->toBe(CarbonImmutable::now()->addYear()->toDateString());
});

it('carries escalation forward on renewal (renewed lease armed, not null)', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'term_months' => 12,
        'escalation_type' => 'fixed_percent', 'escalation_rate' => 7, 'base_rent_monthly' => 50000,
        'security_deposit' => 150000,
    ]);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12, 'new_rent' => 55000, 'commencement_date' => '2027-01-01',
    ]);

    expect($renewal->next_escalation_date?->toDateString())->toBe('2028-01-01') // renewal commencement + 1yr
        ->and($lease->fresh()->status)->toBe('renewed');
});

// ── Terminal leases are immutable ───────────────────────────────────────────────────────────

it('refuses to mutate a terminated lease but still allows soft-delete', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant());
    $lease->update(['status' => 'terminated']); // transition INTO terminal is allowed

    expect(fn () => $lease->update(['base_rent_monthly' => 99999]))->toThrow(DomainException::class);
    expect(fn () => $lease->update(['status' => 'active']))->toThrow(DomainException::class); // can't re-open

    $lease->delete(); // soft-delete only touches deleted_at → allowed
    expect(Lease::withTrashed()->find($lease->id)->trashed())->toBeTrue();
});
