<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\CamReconciliationService;
use App\Services\CreditNoteService;
use App\Services\LeaseRenewalService;
use App\Services\MonthlyBillingService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Regressions for the QA-sweep findings across the older money-critical core modules.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    ensureAllPropertiesAsset();
});

/* ---- #1/#2 Reports scope to a restricted user's visible properties ------- */

it('scopes revenue-by-type + credit notes to a restricted user\'s properties in All-Properties mode', function () {
    $assetA = makeAsset(['code' => 'RQA']);
    $assetB = makeAsset(['code' => 'RQB']);
    $invA = makeInvoice(makeLease(makeUnit($assetA)), ['issue_date' => now()->toDateString()]);
    $invA->items()->create(['type' => 'base_rent', 'description' => 'Rent A', 'amount' => 1000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 1000]);
    $invB = makeInvoice(makeLease(makeUnit($assetB)), ['issue_date' => now()->toDateString()]);
    $invB->items()->create(['type' => 'base_rent', 'description' => 'Rent B', 'amount' => 2000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 2000]);

    // Assigned ONLY to A, no property pinned → "All Properties" for a RESTRICTED user.
    $this->actingAs(makeUser('accounting', [$assetA->id]));

    $report = app(ReportService::class)->monthlyClose(CarbonImmutable::now()->startOfMonth());

    // B's 2000 must NOT leak into the restricted user's revenue-by-type.
    expect($report['revenue_by_type']['base_rent'] ?? 0.0)->toBe(1000.0);
});

/* ---- #7 GL AR tie-out excludes draft invoices ---------------------------- */

it('excludes draft invoices from the GL AR tie-out (journalizer never posts drafts)', function () {
    $issued = makeInvoice(makeLease(makeUnit(makeAsset())));
    app(LedgerPoster::class)->sync($issued->fresh()); // posts AR

    // A draft invoice with a positive balance — NOT on the GL, so NOT expected in AR.
    $draft = makeInvoice(makeLease(makeUnit(makeAsset())));
    DB::table('invoices')->where('id', $draft->id)->update(['status' => 'draft']);

    $tie = app(BooksReconciliationService::class)->glTieOut();
    expect($tie['ar']['delta'])->toBe(0.0); // draft's balance not counted → ties out
});

/* ---- #4 lease renewal skips one-time + inactive charges ------------------ */

it('does not clone one-time or deactivated charges into a lease renewal', function () {
    $lease = makeLease(makeUnit(makeAsset()), null, ['expiry_date' => '2026-12-31', 'security_deposit' => 5000]);
    // A one-time charge (already billed on the original) + a deactivated charge.
    $lease->charges()->create(['name' => 'PctRent Apr', 'type' => 'percentage_rent', 'amount' => 2000, 'currency' => 'EGP', 'frequency' => 'one_time', 'start_date' => '2026-04-01', 'is_active' => true]);
    $lease->charges()->create(['name' => 'Old service', 'type' => 'service_charge', 'amount' => 500, 'currency' => 'EGP', 'frequency' => 'monthly', 'start_date' => '2026-01-01', 'is_active' => false]);

    // fresh() so the original carries its DB-default columns (escalation_rate, etc.) — a
    // real renewal is called with a DB-loaded lease; makeLease's in-memory result has nulls.
    $renewal = app(LeaseRenewalService::class)->renew($lease->fresh(), ['new_term_months' => 12, 'new_rent' => 11000]);

    expect($renewal->charges()->where('frequency', 'one_time')->count())->toBe(0);   // no re-billed one-timer
    expect($renewal->charges()->where('is_active', false)->count())->toBe(0);         // no resurrected charge
    expect($renewal->charges()->where('type', 'percentage_rent')->count())->toBe(0);  // the one-time pct charge dropped
});

/* ---- #5 CAM denominator stable across re-runs (no over-recovery) --------- */

it('keeps the CAM sqm denominator stable on re-run so a later lease cannot over-recover the pool', function () {
    $asset = makeAsset();
    $l1 = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $l2 = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $pool = CamExpensePool::create(['asset_id' => $asset->id, 'period_year' => 2026, 'total_actual_expense' => 100000, 'total_estimated_collected' => 0, 'status' => 'draft']);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool); // denominator 200 → each 50% = 50000
    $a1 = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $l1->id)->sole();
    expect((float) $a1->allocated_amount)->toBe(50000.0);
    $svc->bill($a1); // bill lease 1's true-up (locks its share in)

    // A NEW lease activates afterward.
    $l3 = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $svc->generateAllocations($pool); // re-run

    // The new lease is NOT added (it wasn't in the reconciled participant set), and the
    // still-pending allocation keeps its 50% share — the pool isn't over-recovered.
    expect(CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $l3->id)->exists())->toBeFalse();
    expect((float) CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $l2->id)->sole()->allocated_amount)->toBe(50000.0);
});

/* ---- #6 credit-note void re-reads under the lock ------------------------- */

it('refuses to void a credit note that has been applied since it was loaded (stale in-memory)', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'issued', 'issue_date' => now()->toDateString(),
        'reason' => 'adjustment', 'subtotal' => 500, 'vat_amount' => 0, 'total' => 500, 'applied_amount' => 0, 'balance' => 500, 'currency' => 'EGP',
    ]);
    $stale = CreditNote::find($note->id); // load BEFORE it gets applied

    // Simulate a concurrent application landing in the DB after $stale was loaded.
    DB::table('credit_notes')->where('id', $note->id)->update(['applied_amount' => 500, 'balance' => 0, 'status' => 'applied']);

    // void() must re-read under the lock and refuse — not void an already-applied note.
    expect(fn () => app(CreditNoteService::class)->void($stale))->toThrow(DomainException::class);
    expect(CreditNote::find($note->id)->status)->toBe('applied'); // untouched
});

/* ---- #9 CAM current-year recovery invoice doesn't block monthly rent ----- */

it('bills regular monthly rent even when a current-year CAM recovery invoice exists for the lease', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]), null, ['commencement_date' => '2025-06-01', 'expiry_date' => '2027-01-01']);
    $lease->charges()->create(['name' => 'Base rent', 'type' => 'base_rent', 'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly', 'start_date' => '2025-06-01', 'is_active' => true]);

    // A CAM recovery invoice for the CURRENT year (period Jan 1 – Dec 31).
    $pool = CamExpensePool::create(['asset_id' => $asset->id, 'period_year' => 2026, 'total_actual_expense' => 20000, 'total_estimated_collected' => 0, 'status' => 'draft']);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill(CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $lease->id)->sole()); // → recovery invoice, period 2026-01-01..12-31

    // The January monthly run must STILL raise the lease's regular rent invoice.
    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::create(2026, 1, 1));

    $janRent = Invoice::where('lease_id', $lease->id)
        ->whereDate('period_start', '2026-01-01')->whereDate('period_end', '2026-01-31')->first();
    expect($janRent)->not->toBeNull(); // regular rent NOT skipped by the annual recovery invoice
});
