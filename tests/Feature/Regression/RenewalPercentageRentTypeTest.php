<?php

use App\Services\LeaseRenewalService;

/**
 * Regression: LeaseRenewalService::renew() once dropped
 * percentage_rent_calculation_type when cloning a lease, so an 'artificial'
 * breakpoint config silently reverted to the column default on renewal.
 * It must now carry every percentage-rent field forward.
 */
it('carries percentage-rent fields (including calculation_type) into the renewal', function () {
    $asset = makeAsset(['code' => 'MALL']);
    $unit = makeUnit($asset, ['status' => 'occupied']);
    $lease = makeLease($unit, attrs: [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'term_months' => 12,
        'security_deposit' => 30000,
        'escalation_rate' => 7,
        'escalation_type' => 'fixed_percent',
        'has_percentage_rent' => true,
        'percentage_rent_threshold' => 500000,
        'percentage_rent_rate' => 6.5,
        'percentage_rent_calculation_type' => 'artificial',
    ]);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 11000,
    ]);

    expect((bool) $renewal->has_percentage_rent)->toBeTrue();
    expect((float) $renewal->percentage_rent_threshold)->toBe(500000.0);
    expect((float) $renewal->percentage_rent_rate)->toBe(6.5);
    // The bug: this field was omitted from the renewal's create() payload.
    expect($renewal->percentage_rent_calculation_type)->toBe('artificial');

    // Persisted, not just in-memory.
    expect($renewal->fresh()->percentage_rent_calculation_type)->toBe('artificial');
});
