<?php

use App\Models\CamExpensePool;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\CamReconciliationService;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

/**
 * CAM recovery-clause engine, slice 2 — caps. A cap is the ceiling a tenant's CAM cost share
 * can't exceed in a year. The crux invariant (never re-break): the cap hits ONLY the true-up +
 * the admin-fee base — `allocated_amount` stays UNCAPPED, so the books-check's
 * Σ allocated = total_actual_expense tie-out holds and the landlord's absorbed excess
 * (cap_absorbed) is auditable. No cap term ⇒ byte-identical to the no-cap slice.
 */
afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2027);
});

/** A single 100-sqm lease pool for reconciled year 2026; returns [pool, lease]. */
function capPool(float $actual, float $estimated, ?float $adminFeePct = 0.10): array
{
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => $actual, 'total_estimated_collected' => $estimated,
        'admin_fee_pct' => $adminFeePct, 'status' => 'draft',
    ]);

    return [$pool, $lease];
}

it('caps the true-up + admin fee to an absolute ceiling while allocated stays uncapped', function () {
    [$pool, $lease] = capPool(50000, 30000); // allocated 50000, estimated 30000
    LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2025,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 40000,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);
    $alloc = $pool->allocations()->sole();

    expect((float) $alloc->allocated_amount)->toBe(50000.0)   // UNCAPPED — the tie-out basis
        ->and((float) $alloc->cap_amount)->toBe(40000.0)
        ->and((float) $alloc->capped_cost_amount)->toBe(40000.0)
        ->and((float) $alloc->cap_absorbed_amount)->toBe(10000.0) // landlord absorbs
        ->and((float) $alloc->true_up_amount)->toBe(10000.0)      // 40000 − 30000 (not 20000)
        ->and((float) $alloc->admin_fee_amount)->toBe(4000.0);    // 10% of the CAPPED 40000
});

it('applies a compounding year-on-year ceiling', function () {
    [$pool, $lease] = capPool(50000, 30000);
    LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2024,
        'cap_type' => 'yoy', 'base_year' => 2024, 'base_year_amount' => 30000,
        'yoy_pct' => 0.05, 'compounding' => true,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);
    $alloc = $pool->allocations()->sole();

    // 30000 × 1.05^(2026−2024) = 30000 × 1.1025 = 33075.
    expect((float) $alloc->cap_amount)->toBe(33075.0)
        ->and((float) $alloc->capped_cost_amount)->toBe(33075.0)
        ->and((float) $alloc->true_up_amount)->toBe(3075.0);
});

it('takes the tighter ceiling when cap_type = both', function () {
    [$pool, $lease] = capPool(50000, 30000);
    LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2024,
        'cap_type' => 'both', 'cap_absolute_amount' => 40000,
        'base_year' => 2024, 'base_year_amount' => 30000, 'yoy_pct' => 0.05, 'compounding' => true,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);
    // min(40000, 33075) = 33075.
    expect((float) $pool->allocations()->sole()->cap_amount)->toBe(33075.0);
});

it('flips a positive true-up to a credit when the cap falls below the estimate', function () {
    [$pool, $lease] = capPool(50000, 30000); // uncapped true-up would be +20000
    LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2025,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 25000, // below the 30000 already estimated-paid
    ]);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $alloc = $pool->allocations()->sole();
    expect((float) $alloc->true_up_amount)->toBe(-5000.0); // 25000 − 30000 → tenant over-paid

    $billed = $svc->bill($alloc);
    // Cost over-payment becomes a credit note; the admin fee (10% × 25000 = 2500) bills separately.
    expect($billed->billed_credit_note_id)->not->toBeNull()
        ->and($billed->billed_charge_id)->toBeNull()
        ->and($billed->billed_admin_fee_charge_id)->not->toBeNull()
        ->and((float) InvoiceItem::where('charge_id', $billed->billed_admin_fee_charge_id)->first()->invoice->total)->toBe(2850.0) // 2500 + 350 VAT
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('leaves an uncapped lease byte-identical (no cap term)', function () {
    [$pool, $lease] = capPool(50000, 30000); // no LeaseCamTerm
    app(CamReconciliationService::class)->generateAllocations($pool);
    $alloc = $pool->allocations()->sole();

    expect($alloc->cap_amount)->toBeNull()
        ->and((float) $alloc->capped_cost_amount)->toBe(50000.0)
        ->and((float) $alloc->cap_absorbed_amount)->toBe(0.0)
        ->and((float) $alloc->true_up_amount)->toBe(20000.0) // uncapped
        ->and((float) $alloc->admin_fee_amount)->toBe(5000.0);
});

it('ties out the books end-to-end when a cap applies', function () {
    [$pool, $lease] = capPool(50000, 30000);
    LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2025,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 40000,
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill($pool->allocations()->sole());

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue()
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('picks the latest effective-dated term on or before the reconciled year', function () {
    [$pool, $lease] = capPool(50000, 30000);
    LeaseCamTerm::create(['lease_id' => $lease->id, 'effective_year' => 2024, 'cap_type' => 'absolute', 'cap_absolute_amount' => 45000]);
    LeaseCamTerm::create(['lease_id' => $lease->id, 'effective_year' => 2026, 'cap_type' => 'absolute', 'cap_absolute_amount' => 40000]);
    LeaseCamTerm::create(['lease_id' => $lease->id, 'effective_year' => 2028, 'cap_type' => 'absolute', 'cap_absolute_amount' => 38000]);

    // Reconciling 2026 → the 2026 term (40000) governs, not 2024 or the not-yet-effective 2028.
    expect($lease->resolveCamCeiling(2026))->toBe(40000.0);
});
