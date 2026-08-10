<?php

use App\Models\TenantSalesDeclaration;

/**
 * `sales:scan-missing-declarations` reminds percentage-rent tenants who haven't reported a closed
 * month, so their overage never silently escapes billing. Idempotent (one reminder per lease+period);
 * skips leases that already declared or are still in fit-out grace.
 */
function pctLease(array $attrs = [])
{
    return makeLease(makeUnit(makeAsset()), makeTenant(), array_merge([
        'status' => 'active', 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5,
        'commencement_date' => now()->subYear()->startOfMonth()->toDateString(),
    ], $attrs));
}

function reminderExists($lease): bool
{
    $periodKey = now()->subMonthNoOverflow()->format('Y-m');

    return $lease->tenant->notifications()
        ->where('data->type', 'sales_declaration_reminder')
        ->where('data->lease_id', $lease->id)
        ->where('data->period_key', $periodKey)
        ->exists();
}

it('reminds a percentage-rent tenant with no declaration for the closed month', function () {
    $lease = pctLease();

    $this->artisan('sales:scan-missing-declarations')->assertSuccessful();

    expect(reminderExists($lease))->toBeTrue();
});

it('is idempotent — a second run does not send a second reminder', function () {
    $lease = pctLease();

    $this->artisan('sales:scan-missing-declarations')->assertSuccessful();
    $this->artisan('sales:scan-missing-declarations')->assertSuccessful();

    expect($lease->tenant->notifications()->where('data->type', 'sales_declaration_reminder')->count())->toBe(1);
});

it('does not remind a lease that already declared the period', function () {
    $lease = pctLease();
    $periodStart = now()->subMonthNoOverflow()->startOfMonth();
    TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => $periodStart->toDateString(),
        'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
        'declared_sales' => 100000, 'calculated_percentage_rent' => 0, 'status' => 'submitted',
        'declared_at' => now(), 'declared_by_type' => $lease->tenant::class, 'declared_by_id' => $lease->tenant_id,
    ]);

    $this->artisan('sales:scan-missing-declarations')->assertSuccessful();

    expect(reminderExists($lease))->toBeFalse();
});

it('does not remind a lease still inside its fit-out grace for the period', function () {
    // Commenced last month with a 3-month fit-out → not billable for last month → not reportable.
    $lease = pctLease([
        'commencement_date' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        'rent_commencement_date' => now()->subMonthNoOverflow()->startOfMonth()->addMonths(3)->toDateString(),
        // Gross grace: the lease bills nothing, so it is not chased for a declaration either.
        'fit_out_scope' => \App\Models\Lease::FIT_OUT_GROSS,
    ]);

    $this->artisan('sales:scan-missing-declarations')->assertSuccessful();

    expect(reminderExists($lease))->toBeFalse();
});

it('does not remind a non-percentage-rent lease', function () {
    $lease = pctLease(['has_percentage_rent' => false]);

    $this->artisan('sales:scan-missing-declarations')->assertSuccessful();

    expect($lease->tenant->notifications()->where('data->type', 'sales_declaration_reminder')->count())->toBe(0);
});
