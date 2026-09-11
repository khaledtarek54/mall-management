<?php

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;

/**
 * Regression — mobile §L L4 (drift A9). An operator's ESTIMATE says it is one.
 *
 * A tenant who is chased and still files nothing has the period raised on their behalf by
 * `sales:estimate-missing`: `declared_sales` holds the mall's estimate, the row reads `submitted`, and
 * its percentage rent is 0 until staff lock it — exactly the shape of a declaration the tenant filed.
 * The app showed a turnover the tenant never typed as theirs, beside a 0 that reads "reviewed, nothing
 * due". The column has said which it was since estimates shipped; the resource never sent it.
 *
 * The rows below are written in the shape `EstimateMissingSalesCommand::raise()` writes. The case that
 * earns the file is the THIRD: `PercentageRentCalculationService` builds an `is_estimate` of its own
 * that means "not locked yet", which is true of every tenant-filed declaration awaiting review — a
 * field sourced from it would pass the first two cases and call the tenant's own figure an estimate.
 */
function estimateLease(): Lease
{
    return makeLease(makeUnit(makeAsset()), makeTenant(), ['has_percentage_rent' => true, 'percentage_rent_rate' => 5]);
}

function estimateDeclaration(Lease $lease, array $attrs): TenantSalesDeclaration
{
    return TenantSalesDeclaration::create(array_merge([
        'lease_id' => $lease->id,
        'period_start' => now()->startOfMonth()->subMonth(),
        'period_end' => now()->startOfMonth()->subDay(),
        'status' => 'submitted',
        'declared_at' => now(),
    ], $attrs));
}

it('marks the mall\'s estimate as an estimate', function () {
    $lease = estimateLease();
    $estimate = estimateDeclaration($lease, ['declared_sales' => 850000, 'is_estimate' => true]);

    $this->getJson("/api/v1/me/sales-declarations/{$estimate->id}", apiHeaders($lease->tenant))
        ->assertOk()
        ->assertJsonPath('data.isEstimate', true)
        // The figure the app must not present as the tenant's own.
        ->assertJsonPath('data.declaredSales', 850000);
});

it('says so on the list too — the resource is the same one', function () {
    $lease = estimateLease();
    estimateDeclaration($lease, ['declared_sales' => 850000, 'is_estimate' => true]);

    $this->getJson('/api/v1/me/sales-declarations', apiHeaders($lease->tenant))
        ->assertOk()
        ->assertJsonPath('data.0.isEstimate', true);
});

it('does not call a figure the tenant filed an estimate while it waits for review', function () {
    // The collision's tooth: unlocked, like every tenant-filed row awaiting review.
    $lease = estimateLease();
    $filed = estimateDeclaration($lease, ['declared_sales' => 920000]);

    $this->getJson("/api/v1/me/sales-declarations/{$filed->id}", apiHeaders($lease->tenant))
        ->assertOk()
        ->assertJsonPath('data.isLocked', false)
        ->assertJsonPath('data.isEstimate', false);
});

it('does not call a locked declaration the tenant filed an estimate', function () {
    $lease = estimateLease();
    $locked = estimateDeclaration($lease, ['declared_sales' => 920000, 'status' => 'locked', 'locked_at' => now()]);

    $this->getJson("/api/v1/me/sales-declarations/{$locked->id}", apiHeaders($lease->tenant))
        ->assertOk()
        ->assertJsonPath('data.isEstimate', false);
});
