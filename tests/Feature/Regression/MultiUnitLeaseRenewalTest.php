<?php

use App\Services\LeaseRenewalService;

/**
 * Regression: LeaseRenewalService::renew() previously carried only the master
 * unit (leases.unit_id), silently dropping a multi-unit lease's additional
 * units. The fix calls $renewal->syncUnits() with the original's FULL unit set,
 * keeping the same master mirrored to leases.unit_id.
 */
it('renews a multi-unit lease carrying the full unit set with the master preserved', function () {
    $asset = makeAsset(['code' => 'MALL']);
    $master = makeUnit($asset, ['code' => 'A-01', 'status' => 'vacant']);
    $extra = makeUnit($asset, ['code' => 'A-02', 'status' => 'vacant']);

    $lease = makeLease($master, null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'term_months' => 12,
        'security_deposit' => 30000,
        'security_deposit_received' => true,
        'escalation_rate' => 7,
        'escalation_type' => 'fixed_percent',
    ]);
    $lease->syncUnits([$master->id, $extra->id], $master->id);

    // Sanity: the original spans both units under the master.
    expect($lease->units()->pluck('units.id')->all())->toHaveCount(2);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 11000,
    ]);

    // The renewal carries BOTH units, not just the master.
    $renewalUnitIds = $renewal->units()->pluck('units.id')->all();
    expect($renewalUnitIds)->toHaveCount(2)
        ->and($renewalUnitIds)->toContain($master->id)
        ->and($renewalUnitIds)->toContain($extra->id);

    // The same master is mirrored to leases.unit_id and flagged exactly once.
    expect((int) $renewal->fresh()->unit_id)->toBe($master->id)
        ->and($renewal->units()->wherePivot('is_master', true)->count())->toBe(1)
        ->and((int) $renewal->units()->wherePivot('is_master', true)->first()->id)->toBe($master->id);
});

it('keeps a single-unit lease renewal scoped to just its one unit', function () {
    $asset = makeAsset(['code' => 'MALL']);
    $unit = makeUnit($asset, ['status' => 'vacant']);

    $lease = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'term_months' => 12,
        'security_deposit' => 30000,
        'security_deposit_received' => true,
        'escalation_rate' => 7,
        'escalation_type' => 'fixed_percent',
    ]);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 11000,
    ]);

    expect($renewal->units()->pluck('units.id')->all())->toBe([$unit->id])
        ->and((int) $renewal->fresh()->unit_id)->toBe($unit->id);
});
