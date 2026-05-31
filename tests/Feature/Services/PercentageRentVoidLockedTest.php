<?php

use App\Models\Charge;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_threshold' => 100000,
        'percentage_rent_rate' => 5.0,
        'percentage_rent_calculation_type' => 'artificial',
    ]);
    $this->operator = makeUser('manager', [$this->asset->id]);

    $this->declaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth()->subMonth(),
        'period_end' => now()->startOfMonth()->subDay(),
        'declared_sales' => 200000,
        'declared_at' => now()->subDays(5),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);

    // Lock it so we can test voiding from the locked state.
    app(PercentageRentCalculationService::class)->lock($this->declaration, $this->operator, 'Initial lock');
    $this->declaration->refresh();
});

it('voidLocked deactivates the percentage_rent Charge and flips status to disputed', function () {
    $charge = Charge::where('lease_id', $this->lease->id)
        ->where('type', 'percentage_rent')
        ->first();
    expect($charge)->not->toBeNull();
    expect((bool) $charge->is_active)->toBeTrue();
    expect($this->declaration->status)->toBe('locked');

    app(PercentageRentCalculationService::class)
        ->voidLocked($this->declaration, $this->operator, 'Tenant disputed sales figure');

    expect($this->declaration->fresh()->status)->toBe('disputed');
    expect((bool) $charge->fresh()->is_active)->toBeFalse();
    expect($charge->fresh()->end_date)->not->toBeNull();
});

it('voidLocked appends an audit_notes line naming the operator and reason', function () {
    app(PercentageRentCalculationService::class)
        ->voidLocked($this->declaration, $this->operator, 'Ledger error in Tenant accounting');

    $notes = $this->declaration->fresh()->audit_notes;
    expect($notes)->toContain($this->operator->name);
    expect($notes)->toContain('Ledger error in Tenant accounting');
});

it('voidLocked is a no-op on a non-locked declaration (idempotency guard)', function () {
    $sibling = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth()->subMonths(2),
        'period_end' => now()->startOfMonth()->subMonth()->subDay(),
        'declared_sales' => 50000,
        'declared_at' => now(),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);

    app(PercentageRentCalculationService::class)
        ->voidLocked($sibling, $this->operator, 'cannot void a submitted declaration');

    expect($sibling->fresh()->status)->toBe('submitted');
});

it('voidLocked only deactivates the period-specific Charge, leaving sibling-period charges alone', function () {
    // Sibling period — its own locked declaration + charge.
    $siblingDeclaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfMonth()->subMonths(2),
        'period_end' => now()->startOfMonth()->subMonth()->subDay(),
        'declared_sales' => 300000,
        'declared_at' => now()->subDays(40),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);
    app(PercentageRentCalculationService::class)->lock($siblingDeclaration, $this->operator, 'sibling lock');
    $siblingCharge = Charge::where('lease_id', $this->lease->id)
        ->where('type', 'percentage_rent')
        ->whereDate('start_date', $siblingDeclaration->period_start)
        ->first();
    expect($siblingCharge)->not->toBeNull();

    app(PercentageRentCalculationService::class)
        ->voidLocked($this->declaration, $this->operator, 'void only the original period');

    expect((bool) $siblingCharge->fresh()->is_active)->toBeTrue();
});
