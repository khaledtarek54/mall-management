<?php

require __DIR__.'/boot.php';
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseOption;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSalesDeclaration;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\MonthEndReadinessService;
use App\Services\Accounting\PeriodService;
use App\Services\BillSecurityDepositService;
use App\Services\BillUnitOwnershipsService;
use App\Services\ChargeScheduleService;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\ExerciseLeaseOptionService;
use App\Services\LeaseCreationService;
use App\Services\LeaseReliefService;
use App\Services\LeaseTerminationService;
use App\Services\MarketingLevyService;
use App\Services\MonthlyBillingService;
use App\Services\PercentageRentCalculationService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\Reports\ReportService;
use App\Services\VendorBillService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/* ─────────────────────────────────────────────────────────────────────────────
   A whole month, on ONE property, with one lease of every shape the engine
   supports and every option type on the books. September 2026 — 30 days, so the
   proration arithmetic is checkable by hand.
   ───────────────────────────────────────────────────────────────────────────── */
$MONTH = CarbonImmutable::parse('2026-09-01');
$END = $MONTH->endOfMonth();

$asset = Asset::create(['name' => 'Month Cycle Mall', 'code' => 'MCM', 'type' => 'mall', 'city' => 'Cairo',
    'country' => 'Egypt', 'currency' => 'EGP', 'is_active' => true, 'total_area_sqm' => 20000, 'leasable_area_sqm' => 16000]);
$tenants = Tenant::whereDoesntHave('unitOwnerships')->take(25)->get()->values();
$billing = app(MonthlyBillingService::class);
$sched = app(ChargeScheduleService::class);
$u = 0;
$t = 0;
// A real closure, not an arrow fn: an arrow fn captures by VALUE, so `++$u` would never persist
// and every unit would be minted as M-001.
$unit = function (float $area = 100) use (&$u, $asset): Unit {
    return Unit::create(['asset_id' => $asset->id,
        'code' => 'M-'.str_pad((string) ++$u, 3, '0', STR_PAD_LEFT), 'category' => 'retail',
        'area_sqm' => $area, 'status' => 'vacant']);
};
$tenantFor = function () use ($tenants, &$t) {
    return $tenants[$t++ % $tenants->count()];
};
$lease = function (array $a, ?float $svc = null) use ($unit, $tenantFor): Lease {
    $l = Lease::create(array_merge([
        'tenant_id' => $tenantFor()->id, 'unit_id' => $unit()->id, 'status' => 'active', 'currency' => 'EGP',
        'commencement_date' => '2025-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 48,
        'service_charge_monthly' => 0, 'has_marketing_levy' => false, 'billing_frequency' => 'monthly',
        'payment_terms_days' => 7, 'escalation_type' => 'none',
    ], $a));
    LeaseCreationService::seedStandardCharges($l, (float) $l->base_rent_monthly,
        $svc ?? (float) $l->service_charge_monthly, $l->commencement_date);

    return $l->fresh('charges');
};
$L = [];   // shape => lease
$expect = []; // shape => expected subtotal for September

qa_section('SETUP — one lease of every shape');

// 1 · plain monthly, mid-term
$L['plain'] = $lease(['base_rent_monthly' => 30000, 'service_charge_monthly' => 10000], 10000);
$expect['plain'] = 40000.00;

// 2 · quarterly, September IS a cycle start (commenced 1 Mar → Mar/Jun/Sep/Dec)
$L['quarterly_start'] = $lease(['base_rent_monthly' => 20000, 'commencement_date' => '2026-03-01', 'billing_frequency' => 'quarterly']);
$expect['quarterly_start'] = 60000.00;                 // 3 × 20,000, whole quarter in advance

// 3 · quarterly, September is MID-cycle (commenced 1 Jan → Jan/Apr/Jul/Oct)
$L['quarterly_mid'] = $lease(['base_rent_monthly' => 20000, 'commencement_date' => '2026-01-01', 'billing_frequency' => 'quarterly']);
$expect['quarterly_mid'] = 0.0;                        // off-cycle

// 4 · annual, September IS the cycle start
$L['annual'] = $lease(['base_rent_monthly' => 15000, 'commencement_date' => '2025-09-01', 'billing_frequency' => 'annual']);
$expect['annual'] = 180000.00;                         // 12 × 15,000

// 5 · mid-month commencement. The SCHEDULED RUN prorates the leading edge (`prorate: true`, fixed
//     2026-08-08 — before that only the manual action prorated, so every mid-month move-in the
//     sweep reached first was billed a full month). Note `planInvoiceForLease()`'s own default is
//     `false`, which reads the other way round; every real caller passes true.
$L['mid_start'] = $lease(['base_rent_monthly' => 30000, 'commencement_date' => '2026-09-16']);
$expect['mid_start'] = round(30000 * 15 / 30, 2);   // 16–30 Sep = 15 days

// 6 · mid-month expiry — the trailing edge is ALWAYS prorated
$L['mid_end'] = $lease(['base_rent_monthly' => 30000, 'expiry_date' => '2026-09-18']);
$expect['mid_end'] = round(30000 * 18 / 30, 2);        // 18,000

// 7 · fit-out, GROSS — nothing bills
$L['fitout_gross'] = $lease(['base_rent_monthly' => 30000, 'service_charge_monthly' => 10000,
    'commencement_date' => '2026-08-01', 'rent_commencement_date' => '2026-11-01', 'fit_out_scope' => 'gross'], 10000);
$expect['fitout_gross'] = 0.0;

// 8 · fit-out, RENT ONLY — the service charge still bills
$L['fitout_net'] = $lease(['base_rent_monthly' => 30000, 'service_charge_monthly' => 10000,
    'commencement_date' => '2026-08-01', 'rent_commencement_date' => '2026-11-01', 'fit_out_scope' => 'rent_only'], 10000);
$expect['fitout_net'] = 10000.00;

// 9 · rent commences mid-month — rent clipped, service charge whole
$L['rent_comm'] = $lease(['base_rent_monthly' => 30000, 'service_charge_monthly' => 10000,
    'commencement_date' => '2026-09-01', 'rent_commencement_date' => '2026-09-15'], 10000);
$expect['rent_comm'] = round(30000 * 16 / 30, 2) + 10000;   // 15 Sep–30 Sep = 16 days

// 10 · holdover — expired, but billing on at 125%
$ho = $lease(['base_rent_monthly' => 40000, 'commencement_date' => '2024-01-01', 'expiry_date' => '2026-07-31']);
app(ConvertLeaseToHoldoverService::class)->convert($ho->fresh(), ['effective_from' => '2026-08-01', 'rate_pct' => 125, 'reason' => 'QA']);
$L['holdover'] = $ho->fresh('charges');
$expect['holdover'] = 50000.00;

// 11 · escalation stepping ON 1 September
$esc = $lease(['base_rent_monthly' => 50000, 'commencement_date' => '2025-09-01', 'expiry_date' => '2029-08-31',
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
$sched->projectTermEscalations($esc->fresh());
$L['escalating'] = $esc->fresh('charges');
$expect['escalating'] = 55000.00;                      // the 1 Sep step

// 12 · relief window covering September (50% off rent)
$rel = $lease(['base_rent_monthly' => 60000]);
app(LeaseReliefService::class)->grant($rel->fresh(), ['type' => 'base_rent', 'from' => '2026-08-01',
    'to' => '2026-10-31', 'percent_off' => 50, 'reason' => 'QA relief']);
$L['relief'] = $rel->fresh('charges');
$expect['relief'] = 30000.00;

// 13 · multi-unit
$mu = $lease(['base_rent_monthly' => 70000]);
$extraUnit = $unit(250);
$mu->syncUnits([$mu->unit_id, $extraUnit->id], $mu->unit_id);
$L['multi_unit'] = $mu->fresh('charges');
$expect['multi_unit'] = 70000.00;

// 14 · marketing levy (5% of base rent, VAT-exempt)
$lv = $lease(['base_rent_monthly' => 80000, 'has_marketing_levy' => true]);
app(MarketingLevyService::class)->createLevyCharge($lv->fresh());
$L['levy'] = $lv->fresh('charges');
$expect['levy'] = 80000.00 + 4000.00;

// 15 · percentage rent — a locked declaration produces its own overage invoice
$pr = $lease(['base_rent_monthly' => 50000, 'has_percentage_rent' => true, 'percentage_rent_threshold' => 1000000,
    'percentage_rent_rate' => 8, 'percentage_rent_calculation_type' => 'artificial', 'percentage_rent_frequency' => 'monthly']);
$L['pct_rent'] = $pr;
$expect['pct_rent'] = 50000.00;                        // the RENT invoice; overage is its own doc

// 16 · expired lease — bills nothing
$L['expired'] = $lease(['base_rent_monthly' => 25000, 'commencement_date' => '2023-01-01', 'expiry_date' => '2026-06-30']);
$expect['expired'] = 0.0;

// 17 · terminated mid-August — bills nothing in September
$term = $lease(['base_rent_monthly' => 25000]);
app(LeaseTerminationService::class)->terminate($term->fresh(), ['termination_date' => '2026-08-20',
    'reason' => 'QA', 'cancel_open_invoices' => true, 'credit_unearned' => true]);
$L['terminated'] = $term->fresh();
$expect['terminated'] = 0.0;

printf("  %d leases built on %s\n", count($L), $asset->name);

qa_section('OPTIONS — every type on the books before the run');
$optionLeases = [];
foreach (LeaseOption::TYPES as $type) {
    $ol = $lease(['base_rent_monthly' => 45000]);
    $optionLeases[$type] = $ol;
    LeaseOption::create(['lease_id' => $ol->id, 'type' => $type, 'status' => 'open',
        'earliest_notice_date' => '2026-06-01', 'latest_notice_date' => '2026-11-30']
        + (in_array($type, LeaseOption::ENCUMBERING_TYPES, true) ? ['unit_id' => $unit()->id] : [])
        + (in_array($type, ['renewal', 'expansion'], true)
            ? ['term_months' => 24, 'rent_basis' => 'uplift_percent', 'uplift_percent' => 5] : []));
}
qa_eq('one open option of every type', count(LeaseOption::TYPES),
    LeaseOption::whereIn('lease_id', collect($optionLeases)->pluck('id'))->where('status', 'open')->count());
$rentBefore = (float) $optionLeases['renewal']->fresh()->base_rent_monthly;
app(ExerciseLeaseOptionService::class)->exercise(
    LeaseOption::where('lease_id', $optionLeases['renewal']->id)->first(), ['notice_given_at' => '2026-09-05']);
qa_ok('exercising an option does not change what the lease bills this month',
    (float) $optionLeases['renewal']->fresh()->base_rent_monthly === $rentBefore,
    'rent still '.number_format($rentBefore, 2));

// Asked BEFORE the run: since SW-052 the plan itself answers already_billed (subtotal 0) once
// the month is raised, so this probe only means anything while the month is still unbilled.
// The run and the manual plan must disagree ONLY on the prorate flag, and deliberately.
$manual = $billing->planInvoiceForLease($L['mid_start']->fresh('charges'), $MONTH, $END, prorate: false);
qa_eq('the un-prorated plan bills the whole month, on request', 30000.00, round($manual['subtotal'], 2));

qa_section('THE RUN — preview, then post');
$preview = $billing->previewForPeriod($MONTH, $asset->id);
$lastId = (int) Invoice::max('id');
$run = $billing->runForPeriod($MONTH, $asset->id);
$raised = Invoice::where('id', '>', $lastId)->where('status', '!=', 'cancelled')->get();
printf("  preview will_bill=%d total=%s · run created=%d skipped=%d failed=%d · raised=%d totalling %s\n",
    $preview['totals']['will_bill'], number_format($preview['totals']['total'], 2),
    $run['created'], $run['skipped'], $run['failed'], $raised->count(), number_format((float) $raised->sum('total'), 2));
qa_eq('the run created exactly what the preview promised', $preview['totals']['will_bill'], $run['created']);
qa_eq('…for the same money', round($preview['totals']['total'], 2), round((float) $raised->sum('total'), 2), 0.05);
qa_eq('nothing failed', 0, $run['failed']);

qa_section('EVERY SHAPE BILLED WHAT IT SHOULD');
$billed = [];
foreach ($L as $shape => $l) {
    $inv = $raised->firstWhere('lease_id', $l->id);
    $billed[$shape] = $inv ? round((float) $inv->subtotal, 2) : 0.0;
    qa_eq(sprintf('%-16s', $shape), $expect[$shape], $billed[$shape], 0.02);
}

qa_section('VAT — rent exempt, service charge taxed, levy exempt');
$plainInv = $raised->firstWhere('lease_id', $L['plain']->id);
qa_eq('plain lease VAT = 14% of the service charge only', 1400.00, round((float) $plainInv->vat_amount, 2));
$levyInv = $raised->firstWhere('lease_id', $L['levy']->id);
qa_eq('a marketing levy carries no VAT', 0.00, round((float) $levyInv->vat_amount, 2));
qa_eq('…and the levy is 5% of base rent', 4000.00,
    round((float) $levyInv->items->firstWhere('type', 'marketing')?->amount, 2));

qa_section('PERCENTAGE RENT — declared, locked, billed as its own document');
$decl = TenantSalesDeclaration::create(['lease_id' => $L['pct_rent']->id,
    'period_start' => $MONTH->toDateString(), 'period_end' => $END->toDateString(),
    'declared_sales' => 1800000, 'gross_sales' => 1800000, 'status' => 'submitted',
    'declared_at' => $END->toDateString()]);
$overage = app(PercentageRentCalculationService::class)->calculate($decl);
qa_eq('overage = 8% of sales above the 1,000,000 breakpoint', 64000.00, $overage);

qa_section('UNIT OWNERS — the صيانة run on the same night');
$owner = Tenant::whereIn('id', UnitOwnership::pluck('tenant_id'))->first();
$ownedUnit = $unit(120);
$ownership = UnitOwnership::create(['asset_id' => $asset->id, 'unit_id' => $ownedUnit->id, 'tenant_id' => $owner->id,
    'tenure_type' => 'freehold', 'status' => 'handed_over', 'assessment_basis' => 'area', 'ownership_share_pct' => 100,
    'started_at' => '2026-01-01', 'handover_date' => '2026-01-01', 'payment_terms_days' => 15, 'currency' => 'EGP']);
Charge::create(['unit_ownership_id' => $ownership->id, 'name' => 'صيانة', 'type' => 'service_charge', 'amount' => 2400,
    'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => true, 'vat_rate' => null,
    'start_date' => '2026-01-01', 'is_active' => true]);
$assess = app(BillUnitOwnershipsService::class)->runForPeriod($MONTH, $asset->id);
printf("  assessment run: %s\n", json_encode($assess));
qa_eq('the owner is assessed', 1, $assess['created']);
qa_eq('…and nothing is left unconfigured', 0, $assess['unconfigured']);

qa_section('DEPOSITS, COSTS AND COLLECTIONS');
$depLease = $L['plain'];
$depLease->forceFill(['security_deposit' => 120000])->save();
$depInv = app(BillSecurityDepositService::class)->bill($depLease->fresh());
qa_eq('a security deposit is billed with no VAT', 0.00, round((float) $depInv->vat_amount, 2));

$vendor = Vendor::first();
$bill = VendorBill::create(['vendor_id' => $vendor->id, 'asset_id' => $asset->id,
    'number' => 'MCM-VB-'.strtoupper(bin2hex(random_bytes(3))), 'bill_date' => '2026-09-10', 'due_date' => '2026-10-10',
    'category' => 'cleaning_security', 'subtotal' => 120000, 'vat_amount' => 16800, 'total' => 136800,
    'status' => 'draft', 'currency' => 'EGP', 'description' => 'September cleaning']);
app(VendorBillService::class)->approve($bill->fresh());
Expense::create(['asset_id' => $asset->id, 'vendor_id' => $vendor->id, 'category' => 'utilities',
    'description' => 'September electricity', 'amount' => 45000, 'vat_amount' => 6300, 'total' => 51300,
    'expense_date' => '2026-09-12', 'status' => 'recorded', 'payment_method' => 'bank_transfer', 'currency' => 'EGP']);

$collected = 0.0;
foreach ($raised->take(8) as $i => $inv) {
    $amt = $i % 3 === 0 ? round((float) $inv->total, 2) : round((float) $inv->total / 2, 2);
    if ($amt <= 0) {
        continue;
    }
    $p = Payment::create(['tenant_id' => $inv->tenant_id, 'amount' => $amt, 'payment_date' => '2026-09-20',
        'method' => 'bank_transfer', 'status' => 'captured']);
    DB::transaction(function () use ($p, $inv, $amt) {
        $p->invoices()->sync([$inv->id => ['allocated_amount' => $amt]]);
        $p->assertInvoicesNotOverAllocated([$inv->id]);
    });
    $inv->fresh()->recomputeTotals();
    $collected += $amt;
}
printf("  collected %s across 8 invoices\n", number_format($collected, 2));

qa_section('THE BOOKS');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$ledger = app(LedgerReportService::class);
$tb = $ledger->trialBalance([$asset->id], $MONTH, $END);
printf("  trial balance: Dr %s / Cr %s (balanced=%s)\n", number_format($tb['total_debit'], 2),
    number_format($tb['total_credit'], 2), var_export($tb['balanced'], true));
qa_ok('the trial balance balances', $tb['balanced']);

$is = $ledger->incomeStatement([$asset->id], $MONTH, $END);
printf("  income statement: revenue %s · expenses %s · net %s\n", number_format((float) $is['total_revenue'], 2),
    number_format((float) $is['total_expense'], 2), number_format((float) $is['net_profit'], 2));
qa_eq('net profit = revenue − expenses',
    round((float) $is['total_revenue'] - (float) $is['total_expense'], 2), round((float) $is['net_profit'], 2), 0.02);
qa_ok('the costs reached the P&L', (float) $is['total_expense'] >= 165000 - 0.05,
    number_format((float) $is['total_expense'], 2).' (120,000 cleaning + 45,000 utilities)');

$bs = $ledger->balanceSheet([$asset->id], $END);
printf("  balance sheet: assets %s vs liabilities+equity+net %s (balanced=%s)\n",
    number_format((float) $bs['total_assets'], 2), number_format((float) $bs['total_equity_and_liabilities'], 2),
    var_export($bs['balanced'], true));
qa_ok('the balance sheet balances', $bs['balanced']);

$aging = app(ReportService::class)->arAgingBuckets($END);
$bucketTotal = round((float) collect($aging['buckets'] ?? $aging)->sum(fn ($b) => (float) ($b['amount'] ?? $b['total'] ?? 0)), 2);
$openAr = round((float) Invoice::whereNotIn('status', ['draft', 'cancelled', 'written_off', 'credited'])
    ->where('balance', '>', 0)->sum('balance'), 2);
qa_eq('AR aging totals the real open receivables', $openAr, $bucketTotal, 1.0);

qa_section('CLOSE THE MONTH');
$period = AccountingPeriod::whereDate('starts_on', '<=', $MONTH->toDateString())
    ->whereDate('ends_on', '>=', $MONTH->toDateString())->first();
$readiness = app(MonthEndReadinessService::class)->for($MONTH, $asset->id);
$blocking = collect($readiness['checks'] ?? $readiness)->filter(fn ($c) => ($c['status'] ?? null) === 'fail');
printf("  readiness: %d checks, %d failing\n", count($readiness['checks'] ?? $readiness), $blocking->count());
foreach ($blocking as $c) {
    printf("    FAIL %s — %s\n", $c['key'] ?? '?', mb_substr((string) ($c['detail'] ?? ''), 0, 100));
}
qa_allows('the period closes', fn () => app(PeriodService::class)->closePeriod($period->fresh()));
qa_eq('…and is closed', 'closed', $period->fresh()->status);
qa_refuses('a receipt back-dated into the closed month is refused', function () use ($MONTH, $raised) {
    Payment::create(['tenant_id' => $raised->first()->tenant_id, 'amount' => 100,
        'payment_date' => $MONTH->addDays(10)->toDateString(), 'method' => 'cash', 'status' => 'captured']);
});
$period->forceFill(['status' => 'open'])->save();

qa_section('FINAL TIE-OUT');
qa_assert_tb('this property', $asset->id);
qa_assert_tb('whole book');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
printf("  AR gl=%s expected=%s | AP gl=%s expected=%s\n", number_format($tie['ar']['gl'], 2),
    number_format($tie['ar']['expected'], 2), number_format($tie['ap']['gl'], 2), number_format($tie['ap']['expected'], 2));
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));
qa_eq('deposits tie out', 0, count($rec->depositTieOutDiscrepancies()));
$deep = $rec->run(null, true);
$fails = collect($deep['checks'] ?? [])->filter(fn ($c) => ($c['passed'] ?? true) === false);
printf("  billing:reconcile --deep → %d checks, %d failing\n", count($deep['checks'] ?? []), $fails->count());
foreach ($fails as $f) {
    printf("    FAIL %s: %s\n", $f['key'], json_encode(array_slice($f['discrepancies'], 0, 3)));
}
qa_eq('the deep reconciliation is clean', 0, $fails->count());

qa_section('RE-RUN — the month is idempotent');
$again = $billing->runForPeriod($MONTH, $asset->id);
qa_eq('a second billing run creates nothing', 0, $again['created']);
$assess2 = app(BillUnitOwnershipsService::class)->runForPeriod($MONTH, $asset->id);
qa_eq('a second assessment run creates nothing', 0, $assess2['created']);

qa_summary();
