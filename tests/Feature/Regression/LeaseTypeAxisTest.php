<?php

use App\Models\Lease;
use App\Services\LeaseRenewalService;

/**
 * The lease TYPE axis — Yardi gap-analysis row 42, corrected.
 *
 * The row asked for a `lease_type` column alongside status. It was a false gap: `previous_lease_id`
 * is already written by `LeaseRenewalService` and read by two relations, so "is this a renewal" is a
 * fact the database holds. A column would have been a SECOND source of truth for it — and the two
 * would disagree the first time a renewal was created by a path that forgot to set it.
 *
 * What was genuinely missing was the answer being reachable: nothing derived it and no screen showed
 * it, so "how much of the book is renewals" could not be asked of the rent roll.
 */
it('types a first-generation lease as new', function () {
    $lease = makeLease(makeUnit(makeAsset()), null, ['status' => 'active']);

    expect($lease->leaseType())->toBe(Lease::TYPE_NEW)
        ->and($lease->isRenewal())->toBeFalse();
});

it('types the lease a renewal produced as a renewal', function () {
    $original = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2025-12-31',
        'base_rent_monthly' => 50000,
    ]);

    $renewal = app(LeaseRenewalService::class)->renew($original->fresh(), [
        'new_term_months' => 12,
        'new_rent' => 55000,
    ]);

    expect($renewal->leaseType())->toBe(Lease::TYPE_RENEWAL)
        ->and($renewal->isRenewal())->toBeTrue()
        // Derived from the link the renewal service actually writes — not from a column that has to
        // be remembered separately.
        ->and($renewal->previous_lease_id)->toBe($original->id);
});

it('keeps type independent of status', function () {
    // The whole point of the axis: the ORIGINAL becomes `renewed` (a terminal status) while the
    // lease that carries the tenancy forward is active — and each has its own type. Conflating the
    // two axes is what made "an active lease that came from a renewal" unrepresentable.
    $original = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2025-12-31',
        'base_rent_monthly' => 50000,
    ]);

    $renewal = app(LeaseRenewalService::class)->renew($original->fresh(), [
        'new_term_months' => 12,
        'new_rent' => 55000,
    ]);

    expect($original->fresh()->status)->toBe('renewed')
        ->and($original->fresh()->leaseType())->toBe(Lease::TYPE_NEW)
        ->and($renewal->status)->toBe('active')
        ->and($renewal->leaseType())->toBe(Lease::TYPE_RENEWAL);
});

it('splits the book by type in a query the rent roll can run', function () {
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['code' => 'N-1']), null, ['status' => 'active']);

    $original = makeLease(makeUnit($asset, ['code' => 'R-1']), null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2025-12-31',
        'base_rent_monthly' => 50000,
    ]);
    app(LeaseRenewalService::class)->renew($original->fresh(), ['new_term_months' => 12, 'new_rent' => 55000]);

    expect(Lease::query()->whereNotNull('previous_lease_id')->count())->toBe(1)
        ->and(Lease::query()->whereNull('previous_lease_id')->count())->toBe(2);
});
