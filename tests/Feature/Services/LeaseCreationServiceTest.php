<?php

use App\Models\Charge;
use App\Models\Tenant;
use App\Services\LeaseCreationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
    $this->unit = makeUnit($this->asset, ['status' => 'vacant']);
});

it('creates a lease + base rent + service charge and marks the unit occupied', function () {
    $tenant = makeTenant();

    $lease = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => $tenant->id,
        'lease' => [
            'unit_id' => $this->unit->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 10000,
            'service_charge_monthly' => 1500,
        ],
    ]);

    expect($lease->tenant_id)->toBe($tenant->id);
    expect($lease->unit_id)->toBe($this->unit->id);
    expect($lease->status)->toBe('active');
    expect($lease->term_months)->toBe(12);
    expect((float) $lease->base_rent_monthly)->toBe(10000.0);
    expect((float) $lease->service_charge_monthly)->toBe(1500.0);
    expect($lease->reference)->toContain('MALL');

    // Expiry = commencement + termMonths - 1 day → 2026-12-31
    expect($lease->expiry_date->toDateString())->toBe('2026-12-31');

    expect($this->unit->fresh()->status)->toBe('occupied');

    $charges = Charge::where('lease_id', $lease->id)->get();
    expect($charges)->toHaveCount(2);

    $rent = $charges->firstWhere('type', 'base_rent');
    expect((float) $rent->amount)->toBe(10000.0);
    expect((bool) $rent->vat_applicable)->toBeFalse();
    expect((float) $rent->vat_rate)->toBe(0.0);

    $svc = $charges->firstWhere('type', 'service_charge');
    expect((float) $svc->amount)->toBe(1500.0);
    expect((bool) $svc->vat_applicable)->toBeTrue();
    expect((float) $svc->vat_rate)->toBe(14.0);
});

it('skips the service charge row when service_charge_monthly is zero', function () {
    $tenant = makeTenant();

    $lease = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => $tenant->id,
        'lease' => [
            'unit_id' => $this->unit->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 6,
            'base_rent_monthly' => 8000,
            // service_charge_monthly omitted → defaults to 0
        ],
    ]);

    $charges = Charge::where('lease_id', $lease->id)->get();
    expect($charges)->toHaveCount(1);
    expect($charges->first()->type)->toBe('base_rent');
});

it('creates a new tenant when tenant_mode is "new"', function () {
    $lease = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'new',
        'tenant' => [
            'name' => 'Fresh Tenant Co',
            'email' => 'fresh@example.test',
            'password' => 'secret-pw',
            'phone' => '+201111111111',
            'type' => 'company',
        ],
        'lease' => [
            'unit_id' => $this->unit->id,
            'commencement_date' => '2026-03-01',
            'term_months' => 12,
            'base_rent_monthly' => 7000,
        ],
    ]);

    $tenant = Tenant::find($lease->tenant_id);
    expect($tenant)->not->toBeNull();
    expect($tenant->name)->toBe('Fresh Tenant Co');
    expect($tenant->email)->toBe('fresh@example.test');
    expect($tenant->status)->toBe('active');
    expect(Hash::check('secret-pw', $tenant->password))->toBeTrue();
});

it('applies defaults: security_deposit = rent * 3, escalation_rate = 7%, payment_terms = 7 days', function () {
    $tenant = makeTenant();

    $lease = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => $tenant->id,
        'lease' => [
            'unit_id' => $this->unit->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 5000,
        ],
    ]);

    expect((float) $lease->security_deposit)->toBe(15000.0);
    expect((float) $lease->escalation_rate)->toBe(7.0);
    expect($lease->escalation_type)->toBe('fixed_percent');
    expect((int) $lease->payment_terms_days)->toBe(7);
    expect($lease->currency)->toBe('EGP');
});

it('refuses to create a lease on a unit that already has an active lease', function () {
    $existingTenant = makeTenant();
    makeLease($this->unit, $existingTenant, ['status' => 'active']);

    $newTenant = makeTenant();

    expect(fn () => app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => $newTenant->id,
        'lease' => [
            'unit_id' => $this->unit->id,
            'commencement_date' => '2026-06-01',
            'term_months' => 12,
            'base_rent_monthly' => 5000,
        ],
    ]))->toThrow(ValidationException::class);
});

it('honours overrides for security_deposit, escalation_rate, payment_terms_days', function () {
    $tenant = makeTenant();

    $lease = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => $tenant->id,
        'lease' => [
            'unit_id' => $this->unit->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 5000,
            'security_deposit' => 20000,
            'escalation_rate' => 10,
            'payment_terms_days' => 30,
        ],
    ]);

    expect((float) $lease->security_deposit)->toBe(20000.0);
    expect((float) $lease->escalation_rate)->toBe(10.0);
    expect((int) $lease->payment_terms_days)->toBe(30);
});
