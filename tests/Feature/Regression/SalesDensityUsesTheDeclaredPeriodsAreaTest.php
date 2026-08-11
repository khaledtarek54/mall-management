<?php

use App\Filament\Admin\Widgets\TopTenants;
use App\Models\TenantSalesDeclaration;
use App\Services\RemeasureUnitService;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Sales density is sales ÷ the area that was leased DURING the declared month (2026-08-11).
 *
 * THE BUG. `TopTenants::salesDensityFor()` divided by `$lease->unit?->area_sqm`, which was wrong
 * twice over:
 *
 *   1. **Master unit only.** A lease over three shops was divided by one of them, so its density
 *      came out several times too high — the exact trap `Lease::totalAreaSqm()`'s docblock names
 *      ("Reading the master alone understates a multi-unit lease by its whole non-master
 *      footprint").
 *   2. **Today's measurement.** A month's sales divided by an area that may have been remeasured
 *      since.
 *
 * Operators rank tenants by this number, so both defects reorder the list the widget exists to
 * produce. Driven through the real widget rather than the helper, because the helper was never
 * the thing that was wrong.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager'));

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'D-'.uniqid(), 'area_sqm' => 100]);
    $this->lease = makeLease($this->unit, null, [
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2027-12-31',
        'base_rent_monthly' => 50000,
    ]);

    TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->lease->tenant_id,
        'period_start' => '2025-03-01',
        'period_end' => '2025-03-31',
        'declared_sales' => 500000,
        'declared_at' => '2025-04-02',
        'status' => 'locked',
    ]);
});

it('divides by the area in force during the declared month, not today\'s', function () {
    // The control first: 500,000 / 100 m² = 5,000 per m².
    Livewire::test(TopTenants::class)
        ->assertTableColumnStateSet('sales_density', 5000.0, $this->lease);

    // The shop is knocked through in 2026 — long after the March 2025 sales it declared.
    app(RemeasureUnitService::class)->record($this->unit, 500, ['effective_from' => '2026-06-01']);

    // Before the fix this became 500,000 / 500 = 1,000, and the tenant dropped down the ranking
    // for a wall that moved a year after the sales it is ranked on.
    Livewire::test(TopTenants::class)
        ->assertTableColumnStateSet('sales_density', 5000.0, $this->lease->fresh());
});

it('counts every unit on a multi-unit lease, not just the master', function () {
    $second = makeUnit($this->asset, ['code' => 'D2-'.uniqid(), 'area_sqm' => 400]);
    $this->lease->syncUnits([$this->unit->id, $second->id], $this->unit->id);

    // 500,000 over 500 m² = 1,000 per m². Reading the master alone gave 5,000 — five times too
    // high, and enough to put this tenant top of a list it does not belong at the top of.
    Livewire::test(TopTenants::class)
        ->assertTableColumnStateSet('sales_density', 1000.0, $this->lease->fresh());
});
