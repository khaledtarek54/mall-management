<?php

use App\Models\FacilityWorkOrder;
use App\Models\JournalEntry;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Models\SlaPenalty;
use App\Models\SlaPolicy;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;
use App\Services\Accounting\FiscalCalendar;
use App\Services\ApplySlaPenaltyService;
use App\Services\AssessSlaPenaltyService;
use App\Services\FacilityWorkOrderService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\MorphMap;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — three defects found by the round-2 gap analysis of modules 21–28
 * (2026-07-16), the first audit those modules ever received.
 *
 * Each was reported by an agent and then re-verified by hand against the code before being
 * fixed, per docs/gap-analysis/README.md's round-2 methodology: an absence claim is a
 * hypothesis, a failure scenario is a finding.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

/* ---- F-77 · a penalty must not be charged to another property's bill ---------- */

/**
 * THE BUG. `ApplySlaPenaltyService::assertBillEligible()` checked vendor + postable +
 * balance, never `asset_id`, and the picker ran `VendorBill::query()->where('vendor_id',…)`
 * with no asset scope (there are no global scopes on any model). A vendor commonly serves
 * several malls, so vendor_id and asset_id are simply not the same question — which is how
 * it was missed. SlaPenaltyJournalizer dimensions the entry to `$bill->asset_id`,
 * so mall BBB absorbed a penalty earned at AAA and AAA never saw the recovery. Exact sibling
 * of the cross-property stock draw `WorkOrderPartService::assertWarehouseServesOrder()`
 * already blocks.
 */
it('refuses to charge a penalty to a vendor bill belonging to another property', function () {
    $aaa = makeAsset(['code' => 'AAA']);
    $bbb = makeAsset(['code' => 'BBB']);
    $vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);

    SlaPolicy::create(['asset_id' => $aaa->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    VendorContract::create([
        'vendor_id' => $vendor->id, 'asset_id' => $aaa->id, 'name' => 'HVAC SLA',
        'status' => 'active', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'value' => 100000, 'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 500,
    ]);

    // A late job at AAA → a chargeable penalty.
    $order = FacilityWorkOrder::create([
        'asset_id' => $aaa->id, 'work_order_type' => 'cm', 'execution_type' => 'external',
        'vendor_id' => $vendor->id, 'description' => 'Chiller down', 'title' => 'Fix chiller',
        'category' => 'hvac', 'priority' => 'urgent', 'scheduled_for' => '2026-07-01',
    ]);
    app(FacilityWorkOrderService::class)->transition($order, 'in_progress');
    test()->travel(6)->hours();
    app(FacilityWorkOrderService::class)->transition($order->fresh(), 'done');

    // ...and the SAME vendor's bill at a DIFFERENT mall.
    $otherBill = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $bbb->id, 'category' => 'maintenance',
        'status' => 'approved', 'bill_date' => '2026-07-05', 'subtotal' => 20000, 'vat_amount' => 0,
    ]);
    $otherBill->recompute();

    $penalty = SlaPenalty::firstOrFail();

    expect(fn () => app(ApplySlaPenaltyService::class)->toBill($penalty, $otherBill->fresh()))
        ->toThrow(DomainException::class);

    // BBB's payable is untouched — it never earned this penalty.
    expect((float) $otherBill->fresh()->balance)->toBe(20000.0)
        ->and($penalty->fresh()->status)->toBe(SlaPenalty::STATUS_FINAL);
});

/* ---- F-78 · waiving an applied penalty must give the money back -------------- */

/**
 * THE BUG. `waive()` guarded only `isWaived()`, so an APPLIED penalty could be waived — and
 * it never called `VendorBill::recompute()`. The bill kept the deduction while the real-time
 * sync saw the journalizer return null (it posts only an APPLIED penalty) and correctly
 * VOIDED the entry. Bill said 9,500, ledger said 10,000: the AP tie-out broke by exactly the
 * penalty and the vendor was underpaid. `detach()` had always recomputed; `waive()` forgot.
 */
it('gives the money back when an applied penalty is waived', function () {
    $asset = makeAsset(['code' => 'WVE']);
    $vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);
    SlaPolicy::create(['asset_id' => $asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    VendorContract::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id, 'name' => 'HVAC SLA',
        'status' => 'active', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'value' => 100000, 'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 500,
    ]);

    $order = FacilityWorkOrder::create([
        'asset_id' => $asset->id, 'work_order_type' => 'cm', 'execution_type' => 'external',
        'vendor_id' => $vendor->id, 'description' => 'Chiller down', 'title' => 'Fix chiller',
        'category' => 'hvac', 'priority' => 'urgent', 'scheduled_for' => '2026-07-01',
    ]);
    app(FacilityWorkOrderService::class)->transition($order, 'in_progress');
    test()->travel(6)->hours();
    app(FacilityWorkOrderService::class)->transition($order->fresh(), 'done');

    $bill = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id, 'category' => 'maintenance',
        'status' => 'approved', 'bill_date' => '2026-07-05', 'subtotal' => 10000, 'vat_amount' => 0,
    ]);
    $bill->recompute();

    app(ApplySlaPenaltyService::class)->toBill(SlaPenalty::firstOrFail(), $bill->fresh());
    expect((float) $bill->fresh()->balance)->toBe(9500.0); // precondition: deducted

    app(AssessSlaPenaltyService::class)->waive(SlaPenalty::firstOrFail(), 'Mall caused the delay.');

    $penalty = SlaPenalty::firstOrFail();

    expect($penalty->status)->toBe(SlaPenalty::STATUS_WAIVED)
        ->and($penalty->vendor_bill_id)->toBeNull('a waived penalty is deducted from nothing')
        ->and((float) $bill->fresh()->balance)->toBe(10000.0, 'the vendor is owed the full amount again')
        ->and((float) $bill->fresh()->penalty_applied_amount)->toBe(0.0);

    // The books and the bills must still agree — the whole point.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);
    expect(app(BooksReconciliationService::class)->glTieOut()['ap']['delta'])->toBe(0.0);
});

/* ---- F-79 · a date-only edit must re-derive the entry's period ---------------- */

/**
 * THE BUG. `LedgerPoster::matches()` compared `asset_id` + the sorted line signature, but
 * `entry_date` appeared NOWHERE in the whole dispatch surface. So a payload differing only
 * in its date matched stale → `sync()` no-op'd → the entry kept its old date AND
 * accounting_period_id. One month's P&L overstates and another understates, permanently.
 * Undetectable by construction: no control account moves (so AR/AP tie-out is blind), and
 * `wouldChange()` reuses `matches()` (so the close gate and `billing:reconcile --deep` are
 * blind too). It bites exactly the two sources deliberately left date-editable —
 * `MarketingSpend.spent_on` and `FixedAsset.acquisition_date`.
 */
it('re-derives the ledger entry when only the document date changes', function () {
    $asset = makeAsset(['code' => 'DTE']);
    $budget = MarketingBudget::create([
        'asset_id' => $asset->id, 'period_year' => (int) now()->year,
        'accrued_amount' => 100000, 'spent_amount' => 0, 'status' => 'open',
    ]);

    $spend = MarketingSpend::create([
        'marketing_budget_id' => $budget->id,
        'description' => 'Ramadan campaign',
        'category' => 'event',
        'amount' => 50000,
        'paid_from' => 'bank',
        'spent_on' => now()->startOfMonth()->toDateString(),
    ]);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $entry = JournalEntry::where('source_type', MorphMap::alias(MarketingSpend::class))
        ->where('source_id', $spend->id)->where('status', 'posted')->firstOrFail();
    expect($entry->entry_date->toDateString())->toBe(now()->startOfMonth()->toDateString());

    // The operator corrects a typo'd date — amounts and accounts unchanged.
    $corrected = now()->startOfMonth()->addMonth()->toDateString();
    $spend->update(['spent_on' => $corrected]);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $live = JournalEntry::where('source_type', MorphMap::alias(MarketingSpend::class))
        ->where('source_id', $spend->id)->where('status', 'posted')->firstOrFail();

    expect($live->entry_date->toDateString())
        ->toBe($corrected, 'a date-only correction must move the entry to the right period');
});
