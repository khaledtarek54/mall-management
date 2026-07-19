<?php

use App\Models\Charge;
use App\Models\JournalLine;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Module-05 close-out. Parking is material recurring mall revenue but its invoice line used to fall
 * through the journalizer's REVENUE_ROLE map into misc_income. It now routes to its own
 * parking_revenue account (41109001). Drives the REAL billing + accounting:sync-ledger sweep.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
});

it('posts a parking invoice line to parking_revenue, not misc income', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => '2026-01-01', 'base_rent_monthly' => 40000,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Parking — 2 bays', 'type' => 'parking',
        'amount' => 3000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::create(2026, 3, 1), false);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $parkingRevenue = (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', '41109001'))->sum('credit');
    $miscIncome = (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', '42101001'))->sum('credit');

    expect($parkingRevenue)->toBe(3000.0)                 // parking landed in its own account
        ->and($miscIncome)->toBe(0.0)                     // NOT misc income
        ->and(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});
