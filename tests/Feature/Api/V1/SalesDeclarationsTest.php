<?php

use App\Models\TenantSalesDeclaration;

function makePercentageLease($tenant)
{
    return makeLease(makeUnit(makeAsset()), $tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 5,
        'percentage_rent_threshold' => 100000,
        'percentage_rent_calculation_type' => 'artificial',
    ]);
}

it('lists the tenant\'s sales declarations', function () {
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);
    TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        'declared_sales' => 150000, 'status' => 'submitted', 'declared_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/me/sales-declarations', apiHeaders($tenant))->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('creates a declaration and computes percentage rent', function () {
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'declared_sales' => 200000,
    ], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.declaredSales', 200000)
        // (200000 - 100000) * 5% = 5000
        ->assertJsonPath('data.calculatedPercentageRent', 5000)
        ->assertJsonPath('data.status', 'submitted');
});

it('rejects a declaration on a lease without percentage rent', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant); // no percentage rent

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id, 'period_start' => '2026-05-01',
        'period_end' => '2026-05-31', 'declared_sales' => 200000,
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['leaseId']);
});

it('rejects a duplicate declaration for the same period', function () {
    $tenant = makeTenant();
    $lease = makePercentageLease($tenant);
    TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'declared_sales' => 100000, 'status' => 'submitted', 'declared_at' => now(),
    ]);

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $lease->id, 'period_start' => '2026-05-01',
        'period_end' => '2026-05-31', 'declared_sales' => 200000,
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['periodStart']);
});

it('rejects a declaration against another tenant\'s lease', function () {
    $tenant = makeTenant();
    $foreignLease = makePercentageLease(makeTenant());

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $foreignLease->id, 'period_start' => '2026-05-01',
        'period_end' => '2026-05-31', 'declared_sales' => 200000,
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['leaseId']);
});
