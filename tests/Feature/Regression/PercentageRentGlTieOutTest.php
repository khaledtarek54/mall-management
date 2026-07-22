<?php

use App\Models\JournalLine;
use App\Models\TenantSalesDeclaration;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\PercentageRentCalculationService;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The percentage-rent overage is a GL money source (invoice item type `percentage_rent` →
 * `percentage_rent_revenue`, 41105001). The GL invariant requires at least one test that drives the
 * REAL service + the `accounting:sync-ledger` sweep and asserts the tie-out — so this locks a
 * billable declaration, sweeps, and asserts Dr AR / Cr percentage_rent_revenue balanced + tied, then
 * voids and asserts the GL reverses.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

function pctRevenue(string $side): float
{
    return (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', '41105001'))->sum($side);
}

function pctTb(): bool
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    return app(LedgerReportService::class)->trialBalance()['balanced'];
}

function pctGlDeclaration(): TenantSalesDeclaration
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active', 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5,
    ]);

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'declared_sales' => 100000, 'calculated_percentage_rent' => 0, 'status' => 'submitted', // → overage 2500
        'declared_at' => now(), 'declared_by_type' => $lease->tenant::class, 'declared_by_id' => $lease->tenant_id,
    ]);
}

it('posts the locked overage to percentage_rent_revenue and ties AR out through the real sweep', function () {
    $decl = pctGlDeclaration();

    app(PercentageRentCalculationService::class)->lock($decl, makeUser('super_admin'), null);

    expect(pctTb())->toBeTrue()
        ->and(pctRevenue('credit'))->toBe(2500.0)   // (100000 − 50000) × 5% = 2500, VAT-free
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);
});

it('reverses the GL when the locked declaration is voided, and the books still tie out', function () {
    $decl = pctGlDeclaration();
    app(PercentageRentCalculationService::class)->lock($decl, makeUser('super_admin'), null);
    expect(pctTb())->toBeTrue()->and(pctRevenue('credit'))->toBe(2500.0);

    app(PercentageRentCalculationService::class)->voidLocked($decl->fresh(), makeUser('super_admin'), 'wrong figure');

    expect(pctTb())->toBeTrue()
        ->and(pctRevenue('credit') - pctRevenue('debit'))->toBe(0.0) // revenue reversed to net zero
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);
});

it('an ANNUAL lease posts each month\'s delta so the year\'s GL revenue telescopes to the annual overage', function () {
    // Annual breakpoint 150,000 @ 10%. Three 100k months → cumulative 300k → annual overage 15,000.
    // The monthly deltas (0 + 5,000 + 10,000) must sum to that in the GL, driven through the real sweep.
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active', 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'annual',
        'percentage_rent_threshold' => 150000, 'percentage_rent_rate' => 10,
    ]);

    $svc = app(PercentageRentCalculationService::class);
    foreach (['2026-01-01' => '2026-01-31', '2026-02-01' => '2026-02-28', '2026-03-01' => '2026-03-31'] as $start => $end) {
        $decl = TenantSalesDeclaration::create([
            'lease_id' => $lease->id, 'period_start' => $start, 'period_end' => $end,
            'declared_sales' => 100000, 'calculated_percentage_rent' => 0, 'status' => 'submitted',
            'declared_at' => now(), 'declared_by_type' => $lease->tenant::class, 'declared_by_id' => $lease->tenant_id,
        ]);
        $svc->lock($decl, makeUser('super_admin'), null);
    }

    // (300,000 − 150,000) × 10% = 15,000 credited to percentage_rent_revenue, balanced + tied.
    expect(pctTb())->toBeTrue()
        ->and(pctRevenue('credit'))->toBe(15000.0)
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);
});
