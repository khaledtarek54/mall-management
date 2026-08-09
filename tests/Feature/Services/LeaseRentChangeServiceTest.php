<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\LeaseRentChangeService;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset, ['status' => 'vacant']);
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'base_rent_monthly' => 50000,
        'service_charge_monthly' => 7500,
    ]);
    // Lease creation via factory bypasses LeaseObserver in test mode (factory
    // uses Lease::create which DOES fire the observer — but the observer only
    // syncs unit status, not charges, so we seed charges explicitly).
    Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 50000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => now(),
        'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Service Charge',
        'type' => 'service_charge',
        'amount' => 7500,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'vat_rate' => 14.00,
        'start_date' => now(),
        'is_active' => true,
    ]);
});

it('dates a newly-created base_rent charge to the lease commencement (missing-charge edge)', function () {
    // A lease whose base_rent Charge is absent — the edge the service recreates.
    $lease = makeLease(makeUnit($this->asset), $this->tenant, [
        'status' => 'active',
        'commencement_date' => '2026-03-01',
        'base_rent_monthly' => 40000,
    ]);
    expect(Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->exists())->toBeFalse();

    app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 45000]);

    $charge = Charge::where('lease_id', $lease->id)
        ->where('type', 'base_rent')
        ->where('is_active', true)
        ->latest('id')
        ->first();

    expect($charge)->not->toBeNull();
    // Commencement date, not now() — consistent with lease creation/renewal.
    expect($charge->start_date->toDateString())->toBe('2026-03-01');
});

it('updates lease columns AND the matching base_rent + service_charge Charge rows', function () {
    app(LeaseRentChangeService::class)->apply($this->lease, [
        'base_rent_monthly' => 60000,
        'service_charge_monthly' => 9000,
        'reason' => 'Year-2 escalation',
    ]);

    expect($this->lease->fresh()->base_rent_monthly)->toEqual(60000);
    expect($this->lease->fresh()->service_charge_monthly)->toEqual(9000);

    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'base_rent')->where('is_active', true)->first()->amount)
        ->toEqual(60000);
    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'service_charge')->where('is_active', true)->first()->amount)
        ->toEqual(9000);

    // The reason now lands on a queryable, attributable lease EVENT rather than being appended as
    // prose to `leases.notes` — a field operators use for their own notes (story LE-01).
    $event = $this->lease->fresh()->events()->first();
    expect($event)->not->toBeNull()
        ->and($event->type)->toBe(\App\Models\LeaseEvent::TYPE_RENT_MODIFICATION)
        ->and($event->reason)->toBe('Year-2 escalation')
        ->and($event->payload['amount_from'])->toEqual(50000)
        ->and($event->payload['amount_to'])->toEqual(60000);
});

it('does not touch non-rent charges on the same lease', function () {
    Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Reserved Parking',
        'type' => 'parking',
        'amount' => 2500,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'vat_rate' => 14.00,
        'start_date' => now(),
        'is_active' => true,
    ]);

    app(LeaseRentChangeService::class)->apply($this->lease, [
        'base_rent_monthly' => 70000,
    ]);

    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'parking')->first()->amount)
        ->toEqual(2500);
});

it('throws when the lease is not in active or pending_approval status', function () {
    $this->lease->update(['status' => 'terminated']);

    app(LeaseRentChangeService::class)->apply($this->lease->fresh(), [
        'base_rent_monthly' => 60000,
    ]);
})->throws(InvalidArgumentException::class, "is 'terminated'");

it('throws when the new rent is negative', function () {
    app(LeaseRentChangeService::class)->apply($this->lease, [
        'base_rent_monthly' => -1,
    ]);
})->throws(InvalidArgumentException::class, 'must be ≥ 0');

it('is idempotent when applied with the same values twice', function () {
    app(LeaseRentChangeService::class)->apply($this->lease, [
        'base_rent_monthly' => 55000,
        'service_charge_monthly' => 8000,
    ]);
    app(LeaseRentChangeService::class)->apply($this->lease->fresh(), [
        'base_rent_monthly' => 55000,
        'service_charge_monthly' => 8000,
    ]);

    // Both rent + service charge rows still exist exactly once.
    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'base_rent')->count())->toBe(1);
    expect(Charge::where('lease_id', $this->lease->id)->where('type', 'service_charge')->count())->toBe(1);
});

it('creates a base_rent Charge if none exists yet (form-driven lease with no observer-seeded charges)', function () {
    Charge::where('lease_id', $this->lease->id)->delete();

    app(LeaseRentChangeService::class)->apply($this->lease, [
        'base_rent_monthly' => 42000,
    ]);

    $base = Charge::where('lease_id', $this->lease->id)->where('type', 'base_rent')->first();
    expect($base)->not->toBeNull();
    expect($base->amount)->toEqual(42000);
    expect($base->vat_applicable)->toBeFalse();
});
