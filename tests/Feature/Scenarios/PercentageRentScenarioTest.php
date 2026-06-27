<?php

/*
|--------------------------------------------------------------------------
| Percentage Rent — NET-NEW scenarios
|--------------------------------------------------------------------------
| Sibling suites already cover: zero-when-no-pct-rent, the artificial /
| natural formulae, floor-at-zero (artificial), basic lock+1-charge,
| idempotent re-lock, skip-charge-when-zero, the void/dispute path, and the
| lock notification firing. (tests/Feature/Services/PercentageRentCalculation
| ServiceTest, PercentageRentVoidLockedTest, Notifications/MaintenanceAndSales
| NotificationsTest.)
|
| This file adds the angles those leave open, asserting the CHARGE AMOUNT and
| the created Charge's attributes EXACTLY:
|   - boundary at / just-above / just-below the breakpoint
|   - natural-breakpoint flooring (only artificial floor was covered)
|   - calculation_type defaulting to 'artificial' when null
|   - the persisted Charge's full attribute set (type/frequency/vat/dates/active)
|   - per-period scoping: each period's charge is bounded to its own period
|   - fractional-rate rounding to 2dp
|   - recalculate() persists without locking and creates no charge (untested)
|   - calculate() is a pure read (no mutation / no charge)
|   - state-transition: re-lock after a void creates a fresh charge, leaving
|     exactly one ACTIVE percentage-rent charge
*/

use App\Models\Charge;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->svc = app(PercentageRentCalculationService::class);
});

/**
 * Build a lease + submitted declaration in one shot. $leaseAttrs is merged
 * onto a sane percentage-rent default; pass period_start/period_end to scope.
 */
function pctDeclaration(array $leaseAttrs, float $sales, array $declAttrs = []): TenantSalesDeclaration
{
    $lease = makeLease(test()->unit, test()->tenant, array_merge([
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 5,
        'base_rent_monthly' => 10000,
    ], $leaseAttrs));

    return TenantSalesDeclaration::create(array_merge([
        'lease_id' => $lease->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'declared_sales' => $sales,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
        'declared_by_type' => test()->tenant::class,
        'declared_by_id' => test()->tenant->id,
    ], $declAttrs));
}

// ----------------------------------------------------------------------------
// BOUNDARY — exactly at, just below, and just above the artificial breakpoint
// ----------------------------------------------------------------------------

it('artificial: sales exactly AT the threshold owes exactly zero and creates no charge', function () {
    // (50000 - 50000) * 0.05 = 0
    $decl = pctDeclaration(['percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5], 50000);

    expect($this->svc->calculate($decl))->toBe(0.0);

    $this->svc->lock($decl, $this->operator);

    expect($decl->lease->charges()->where('type', 'percentage_rent')->count())->toBe(0);
});

it('artificial: one unit BELOW the threshold still owes zero (no negative charge)', function () {
    // (49999 - 50000) * 0.05 = -0.05 -> floored to 0
    $decl = pctDeclaration(['percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5], 49999);

    expect($this->svc->calculate($decl))->toBe(0.0);
});

it('artificial: one unit ABOVE the threshold owes exactly rate × 1', function () {
    // (50001 - 50000) * 0.05 = 0.05
    $decl = pctDeclaration(['percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5], 50001);

    expect($this->svc->calculate($decl))->toBe(0.05);

    $this->svc->lock($decl, $this->operator);

    $charge = $decl->lease->charges()->where('type', 'percentage_rent')->sole();
    expect((float) $charge->amount)->toBe(0.05);
});

// ----------------------------------------------------------------------------
// BOUNDARY — natural breakpoint flooring (only the artificial floor was covered)
// ----------------------------------------------------------------------------

it('natural: owed is floored to zero when base rent exceeds sales × rate', function () {
    // 100000 * 0.05 = 5000; 5000 - 20000 base = -15000 -> 0
    $decl = pctDeclaration([
        'percentage_rent_calculation_type' => 'natural_breakpoint',
        'percentage_rent_rate' => 5,
        'base_rent_monthly' => 20000,
    ], 100000);

    expect($this->svc->calculate($decl))->toBe(0.0);

    $this->svc->lock($decl, $this->operator);
    expect($decl->lease->charges()->where('type', 'percentage_rent')->count())->toBe(0);
});

it('natural: sales exactly AT the natural breakpoint owe exactly zero', function () {
    // natural breakpoint = base_rent / rate = 10000 / 0.05 = 200000.
    // 200000 * 0.05 - 10000 = 0
    $decl = pctDeclaration([
        'percentage_rent_calculation_type' => 'natural_breakpoint',
        'percentage_rent_rate' => 5,
        'base_rent_monthly' => 10000,
    ], 200000);

    expect($this->svc->calculate($decl))->toBe(0.0);
});

// ----------------------------------------------------------------------------
// STATE — calculation_type defaults to 'artificial' when the column is null
// ----------------------------------------------------------------------------

it('defaults to the artificial formula when calculation_type is null', function () {
    // No calculation_type set -> service falls back to artificial.
    // (120000 - 50000) * 0.05 = 3500
    $decl = pctDeclaration(['percentage_rent_calculation_type' => null, 'percentage_rent_threshold' => 50000], 120000);

    expect($decl->lease->percentage_rent_calculation_type)->toBeNull();
    expect($this->svc->calculate($decl))->toBe(3500.0);
});

// ----------------------------------------------------------------------------
// STATE — the created Charge carries the exact billing-ready attribute set
// ----------------------------------------------------------------------------

it('lock writes a one-off, VAT-free percentage_rent charge bounded to the declaration period', function () {
    // (90000 - 50000) * 0.05 = 2000
    $decl = pctDeclaration(
        ['percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5],
        90000,
        ['period_start' => '2026-04-01', 'period_end' => '2026-04-30'],
    );

    $this->svc->lock($decl, $this->operator);

    $charge = $decl->lease->charges()->where('type', 'percentage_rent')->sole();

    expect((float) $charge->amount)->toBe(2000.0);
    expect($charge->type)->toBe('percentage_rent');
    expect($charge->frequency)->toBe('one_time');
    expect((bool) $charge->vat_applicable)->toBeFalse();
    expect((float) $charge->vat_rate)->toBe(0.0);
    expect((bool) $charge->is_active)->toBeTrue();
    expect($charge->currency)->toBe('EGP');
    expect($charge->start_date->toDateString())->toBe('2026-04-01');
    expect($charge->end_date->toDateString())->toBe('2026-04-30');
    // Charge name carries the human period label so billing shows the source.
    expect($charge->name)->toContain($decl->periodLabel());
    expect($charge->name)->toContain('Apr 2026');
});

// ----------------------------------------------------------------------------
// SCOPING — two periods on the same lease each get their own bounded charge
// ----------------------------------------------------------------------------

it('locks two periods independently, each charge bounded to its own period', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 5,
    ]);

    $jan = TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'declared_sales' => 70000, // (70000-50000)*.05 = 1000
        'status' => 'submitted', 'declared_at' => now(),
    ]);
    $feb = TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
        'declared_sales' => 90000, // (90000-50000)*.05 = 2000
        'status' => 'submitted', 'declared_at' => now(),
    ]);

    $this->svc->lock($jan, $this->operator);
    $this->svc->lock($feb, $this->operator);

    expect($lease->charges()->where('type', 'percentage_rent')->count())->toBe(2);

    $janCharge = Charge::where('lease_id', $lease->id)->where('type', 'percentage_rent')
        ->whereDate('start_date', '2026-01-01')->sole();
    $febCharge = Charge::where('lease_id', $lease->id)->where('type', 'percentage_rent')
        ->whereDate('start_date', '2026-02-01')->sole();

    expect((float) $janCharge->amount)->toBe(1000.0);
    expect($janCharge->end_date->toDateString())->toBe('2026-01-31');
    expect((float) $febCharge->amount)->toBe(2000.0);
    expect($febCharge->end_date->toDateString())->toBe('2026-02-28');
});

// ----------------------------------------------------------------------------
// BOUNDARY — fractional rate rounds the owed amount to 2 decimal places
// ----------------------------------------------------------------------------

it('rounds the owed amount to two decimal places for a fractional rate', function () {
    // 2.5% of (33333 - 0) = 833.325 -> 833.33 (round-half-up at 2dp)
    $decl = pctDeclaration([
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 0,
        'percentage_rent_rate' => 2.5,
    ], 33333);

    expect($this->svc->calculate($decl))->toBe(833.33);

    $this->svc->lock($decl, $this->operator);
    $charge = $decl->lease->charges()->where('type', 'percentage_rent')->sole();
    expect((float) $charge->amount)->toBe(833.33);
});

// ----------------------------------------------------------------------------
// STATE — recalculate() persists the amount WITHOUT locking or charging
// ----------------------------------------------------------------------------

it('recalculate persists calculated_percentage_rent without locking and without a charge', function () {
    // (100000 - 50000) * 0.05 = 2500
    $decl = pctDeclaration(['percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5], 100000);

    expect((float) $decl->calculated_percentage_rent)->toBe(0.0);

    $returned = $this->svc->recalculate($decl);

    expect((float) $returned->calculated_percentage_rent)->toBe(2500.0);
    // Persisted, not just in-memory.
    expect((float) $decl->fresh()->calculated_percentage_rent)->toBe(2500.0);
    // Still submitted — recalculate must not lock or bill.
    expect($decl->fresh()->status)->toBe('submitted');
    expect($decl->fresh()->locked_at)->toBeNull();
    expect($decl->lease->charges()->where('type', 'percentage_rent')->count())->toBe(0);
});

// ----------------------------------------------------------------------------
// STATE — calculate() is a pure read: no persistence, no charge
// ----------------------------------------------------------------------------

it('calculate is a pure read and leaves the declaration and charges untouched', function () {
    $decl = pctDeclaration(['percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5], 100000);

    $this->svc->calculate($decl);

    // calculated_percentage_rent is untouched on disk (still the stored 0).
    expect((float) $decl->fresh()->calculated_percentage_rent)->toBe(0.0);
    expect($decl->fresh()->status)->toBe('submitted');
    expect(Charge::where('lease_id', $decl->lease_id)->where('type', 'percentage_rent')->count())->toBe(0);
});

// ----------------------------------------------------------------------------
// STATE-TRANSITION — re-lock after a void recreates a single ACTIVE charge
// ----------------------------------------------------------------------------

it('re-locking a voided (disputed) declaration recreates exactly one active charge', function () {
    // (100000 - 50000) * 0.05 = 2500
    $decl = pctDeclaration(['percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5], 100000);

    // Lock -> 1 active charge.
    $this->svc->lock($decl, $this->operator);
    expect($decl->lease->charges()->where('type', 'percentage_rent')->where('is_active', true)->count())->toBe(1);

    // Void -> status disputed, the charge is deactivated (0 active).
    $this->svc->voidLocked($decl->fresh(), $this->operator, 'tenant disputed figure');
    expect($decl->fresh()->status)->toBe('disputed');
    expect($decl->lease->charges()->where('type', 'percentage_rent')->where('is_active', true)->count())->toBe(0);

    // Re-lock the disputed declaration: the status guard only short-circuits on
    // 'locked', so a disputed declaration locks afresh and bills again.
    $this->svc->lock($decl->fresh(), $this->operator);

    expect($decl->fresh()->status)->toBe('locked');
    // Two rows exist historically (the voided one + the new one)...
    expect($decl->lease->charges()->where('type', 'percentage_rent')->count())->toBe(2);
    // ...but only ONE is active, and it carries the recalculated amount.
    $active = $decl->lease->charges()->where('type', 'percentage_rent')->where('is_active', true)->get();
    expect($active)->toHaveCount(1);
    expect((float) $active->first()->amount)->toBe(2500.0);
});

// ----------------------------------------------------------------------------
// HAPPY/STATE — has_percentage_rent=false: lock is harmless, never charges
// ----------------------------------------------------------------------------

it('a lease without percentage rent locks without owing or charging', function () {
    $decl = pctDeclaration(['has_percentage_rent' => false], 5_000_000);

    $locked = $this->svc->lock($decl, $this->operator);

    expect($locked->status)->toBe('locked');
    expect((float) $locked->calculated_percentage_rent)->toBe(0.0);
    expect($decl->lease->charges()->where('type', 'percentage_rent')->count())->toBe(0);
});
