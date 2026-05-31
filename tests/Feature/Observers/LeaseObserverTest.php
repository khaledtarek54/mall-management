<?php

use App\Models\Lease;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
});

it('flips the unit to occupied when a lease is created with status=active', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);

    $lease = makeLease($unit, $this->tenant, ['status' => 'active']);

    expect($unit->fresh()->status)->toBe('occupied');
});

it('leaves the unit alone when a lease is created with status=draft', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    makeLease($unit, $this->tenant, ['status' => 'draft']);

    expect($unit->fresh()->status)->toBe('vacant');
});

it('flips the unit back to vacant when an existing lease is updated to terminated', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    $lease = makeLease($unit, $this->tenant, ['status' => 'active']);
    expect($unit->fresh()->status)->toBe('occupied');

    $lease->update(['status' => 'terminated']);

    expect($unit->fresh()->status)->toBe('vacant');
});

it('flips the unit to occupied when an existing lease is updated from draft to active', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    $lease = makeLease($unit, $this->tenant, ['status' => 'draft']);
    expect($unit->fresh()->status)->toBe('vacant');

    $lease->update(['status' => 'active']);

    expect($unit->fresh()->status)->toBe('occupied');
});

it('is a no-op when a non-status field is updated on an active lease', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    $lease = makeLease($unit, $this->tenant, ['status' => 'active']);

    $lease->update(['notes' => 'updated']);

    // Unit was already occupied; observer's updated() handler ignores non-status changes.
    expect($unit->fresh()->status)->toBe('occupied');
});

it('does not flip an expired lease back to occupied when status remains expired', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    $lease = makeLease($unit, $this->tenant, ['status' => 'active']);
    $lease->update(['status' => 'expired']);
    expect($unit->fresh()->status)->toBe('vacant');

    // Touching a non-status field should not re-trigger the sync.
    $lease->update(['notes' => 'second update']);
    expect($unit->fresh()->status)->toBe('vacant');
});

it('seeds standard charges via CreateLease afterCreate when none exist', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    $lease = Lease::create([
        'reference' => Lease::generateReference('HW'),
        'unit_id' => $unit->id,
        'tenant_id' => $this->tenant->id,
        'status' => 'active',
        'commencement_date' => now(),
        'expiry_date' => now()->addYear(),
        'term_months' => 12,
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 1500,
        'currency' => 'EGP',
        'payment_terms_days' => 7,
    ]);
    expect($lease->charges()->count())->toBe(0); // observer doesn't seed charges

    // Simulate what CreateLease::afterCreate() does for the standard form.
    \App\Services\LeaseCreationService::seedStandardCharges(
        $lease,
        rent: (float) $lease->base_rent_monthly,
        service: (float) $lease->service_charge_monthly,
    );

    expect($lease->charges()->count())->toBe(2);
    expect($lease->charges()->where('type', 'base_rent')->first()->amount)->toEqual(10000);
    expect($lease->charges()->where('type', 'service_charge')->first()->amount)->toEqual(1500);
});

it('seedStandardCharges is idempotent — second call does not duplicate', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    $lease = Lease::create([
        'reference' => Lease::generateReference('HW'),
        'unit_id' => $unit->id,
        'tenant_id' => $this->tenant->id,
        'status' => 'active',
        'commencement_date' => now(),
        'expiry_date' => now()->addYear(),
        'term_months' => 12,
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 1500,
        'currency' => 'EGP',
        'payment_terms_days' => 7,
    ]);
    \App\Services\LeaseCreationService::seedStandardCharges($lease, 10000, 1500);
    expect($lease->fresh()->charges()->count())->toBe(2);

    // Second call — must not double-seed.
    \App\Services\LeaseCreationService::seedStandardCharges($lease->fresh(), 10000, 1500);
    expect($lease->fresh()->charges()->count())->toBe(2);
});
