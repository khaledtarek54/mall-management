<?php

use App\Models\Charge;
use App\Services\LeaseRenewalService;

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
    $this->unit = makeUnit($this->asset, ['status' => 'occupied']);
    $this->lease = makeLease($this->unit, attrs: [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'term_months' => 12,
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 1500,
        'security_deposit' => 30000,
        'security_deposit_received' => true,
        'escalation_rate' => 7,
        'escalation_type' => 'fixed_percent',
        'payment_terms_days' => 7,
        'has_percentage_rent' => false,
    ]);

    // Original charges that the renewal should clone.
    Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 1500, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Parking', 'type' => 'parking',
        'amount' => 500, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
});

it('refuses to renew a non-active lease', function () {
    $this->lease->update(['status' => 'terminated']);

    app(LeaseRenewalService::class)->renew($this->lease, [
        'new_term_months' => 12,
        'new_rent' => 11000,
    ]);
})->throws(InvalidArgumentException::class);

it('creates renewal with new amounts, marks original renewed, clones charges with new amounts', function () {
    $renewal = app(LeaseRenewalService::class)->renew($this->lease, [
        'new_term_months' => 24,
        'new_rent' => 12000,
        'new_service_charge' => 1800,
    ]);

    // Original is now marked renewed.
    expect($this->lease->fresh()->status)->toBe('renewed');

    // Renewal links back via previous_lease_id and inherits unit/tenant.
    expect($renewal->status)->toBe('active');
    expect($renewal->previous_lease_id)->toBe($this->lease->id);
    expect($renewal->unit_id)->toBe($this->lease->unit_id);
    expect($renewal->tenant_id)->toBe($this->lease->tenant_id);
    expect((int) $renewal->term_months)->toBe(24);

    // Default commencement = original.expiry_date + 1 day
    expect($renewal->commencement_date->toDateString())->toBe('2027-01-01');
    // Expiry = commencement + term - 1 day
    expect($renewal->expiry_date->toDateString())->toBe('2028-12-31');

    // Lease-level rent fields use new values.
    expect((float) $renewal->base_rent_monthly)->toBe(12000.0);
    expect((float) $renewal->service_charge_monthly)->toBe(1800.0);

    // Carries forward deposit + escalation from original.
    expect((float) $renewal->security_deposit)->toBe(30000.0);
    expect((bool) $renewal->security_deposit_received)->toBeTrue();
    expect((float) $renewal->escalation_rate)->toBe(7.0);

    // Charges: 3 cloned (base_rent + service_charge at new values, parking unchanged)
    // + the marketing levy resynced to 5% of the new base rent.
    $charges = Charge::where('lease_id', $renewal->id)->get();
    expect($charges)->toHaveCount(4);

    $rent = $charges->firstWhere('type', 'base_rent');
    expect((float) $rent->amount)->toBe(12000.0);

    $svc = $charges->firstWhere('type', 'service_charge');
    expect((float) $svc->amount)->toBe(1800.0);

    $parking = $charges->firstWhere('type', 'parking');
    expect((float) $parking->amount)->toBe(500.0);

    $marketing = $charges->firstWhere('type', 'marketing');
    expect((float) $marketing->amount)->toBe(600.0); // 5% of new base rent (12000)
    expect((bool) $parking->vat_applicable)->toBeTrue();
});

it('defaults new_service_charge to the original lease value when omitted', function () {
    $renewal = app(LeaseRenewalService::class)->renew($this->lease, [
        'new_term_months' => 12,
        'new_rent' => 11000,
        // new_service_charge omitted
    ]);

    expect((float) $renewal->service_charge_monthly)->toBe(1500.0);

    $svc = Charge::where('lease_id', $renewal->id)
        ->where('type', 'service_charge')->first();
    expect((float) $svc->amount)->toBe(1500.0);
});

it('honours an explicit commencement_date override', function () {
    $renewal = app(LeaseRenewalService::class)->renew($this->lease, [
        'new_term_months' => 12,
        'new_rent' => 11000,
        'commencement_date' => '2027-02-15',
    ]);

    expect($renewal->commencement_date->toDateString())->toBe('2027-02-15');
    expect($renewal->expiry_date->toDateString())->toBe('2028-02-14');
});
