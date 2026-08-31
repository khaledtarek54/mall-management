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

/**
 * A CAP THAT CANNOT RESOLVE IS WORSE THAN NO CAP (2026-08-31).
 *
 * `resolveCeiling()` returns null unless every column its cap type needs is filled. A `yoy` term
 * with no `base_year_amount` SAVES, renders on the lease's CAM tab exactly like a working cap, and
 * caps nothing — so the operator believes the tenant is protected and the reconciliation bills them
 * in full. Measured on the demo books: one of the two seeded cap terms was precisely that.
 *
 * The form has always required these fields. The form is not the only writer — a seeder wrote both
 * of them, and got the second one wrong — so the rule belongs on the model. Same reasoning as
 * `TaxCode` refusing to activate a taxable code with no rate: incomplete must be impossible rather
 * than inert, because inert is invisible.
 */
it('refuses a cap term that would resolve to nothing', function (): void {
    $lease = makeLease(makeUnit(makeAsset()));

    // yoy with no base-year amount and no percentage — the shape found on the demo books.
    expect(fn () => LeaseCamTerm::create([
        'lease_id' => $lease->id,
        'effective_year' => 2027,
        'cap_type' => 'yoy',
        'base_year' => 2026,
    ]))->toThrow(DomainException::class);

    // absolute with no amount
    expect(fn () => LeaseCamTerm::create([
        'lease_id' => $lease->id,
        'effective_year' => 2027,
        'cap_type' => 'absolute',
    ]))->toThrow(DomainException::class);

    // `both` needs BOTH legs — one complete leg would still resolve, but it is not what the
    // operator declared, and a term that quietly caps on one rule is the same class of surprise.
    expect(fn () => LeaseCamTerm::create([
        'lease_id' => $lease->id,
        'effective_year' => 2027,
        'cap_type' => 'both',
        'cap_absolute_amount' => 180_000,
    ]))->toThrow(DomainException::class);

    expect(LeaseCamTerm::where('lease_id', $lease->id)->count())->toBe(0);
});

/**
 * The control, and the half that matters: a guard that refused everything would satisfy the
 * refusals above and make the cap feature unusable.
 */
it('accepts every complete cap term, and each one resolves to a number', function (): void {
    $lease = makeLease(makeUnit(makeAsset()));

    $absolute = LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2027,
        'cap_type' => 'absolute', 'cap_absolute_amount' => 180_000,
    ]);

    $yoy = LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2028,
        'cap_type' => 'yoy', 'base_year' => 2026, 'base_year_amount' => 120_000,
        'yoy_pct' => 0.05, 'compounding' => false,
    ]);

    $both = LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2029,
        'cap_type' => 'both', 'cap_absolute_amount' => 180_000,
        'base_year' => 2026, 'base_year_amount' => 120_000, 'yoy_pct' => 0.05, 'compounding' => false,
    ]);

    expect($absolute->resolveCeiling(2027))->toBe(180000.0)
        // 120,000 base, +5% simple for two years = 132,000.
        ->and($yoy->resolveCeiling(2028))->toBe(132000.0)
        // `both` takes the LOWER of the two ceilings — 138,000 vs 180,000.
        ->and($both->resolveCeiling(2029))->toBe(138000.0);
});

/**
 * `yoy_pct` IS A FRACTION, NOT A PERCENT.
 *
 * `resolveCeiling()` computes base × (1 + pct)^years, and the form stores 0.05 when the operator
 * types 5. A writer that stores 5.0 states a 500%-a-year ceiling, which can never bite — the cap
 * looks configured and the tenant pays everything. That is what the leasing-depth seeder did.
 */
it('reads the annual increase as a fraction', function (): void {
    $lease = makeLease(makeUnit(makeAsset()));

    $term = LeaseCamTerm::create([
        'lease_id' => $lease->id, 'effective_year' => 2027,
        'cap_type' => 'yoy', 'base_year' => 2026, 'base_year_amount' => 100_000,
        'yoy_pct' => 0.05, 'compounding' => false,
    ]);

    // 5% for one year. Were 5.0 stored instead, this would be 600,000 and no real cost would reach it.
    expect($term->resolveCeiling(2027))->toBe(105000.0);
});
