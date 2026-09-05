<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\LeaseCreationService;
use App\Services\LeaseTerminationService;
use App\Services\MonthlyBillingService;
use App\Services\MoveOutStatementService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\SettleMoveOutService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$freeUnits = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$n = 0;
$billing = app(MonthlyBillingService::class);
$mk = function (array $a) use (&$n, $freeUnits, $asset, $tenant): Lease {
    $u = $freeUnits[$n++];
    $l = Lease::create(array_merge([
        'asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'unit_id' => $u->id,
        'reference' => 'QA-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none',
    ], $a));
    LeaseCreationService::seedStandardCharges($l, (float) $l->base_rent_monthly, (float) $l->service_charge_monthly, $l->commencement_date);

    return $l->fresh('charges');
};

qa_section('TERMINATION 1 — mid-month termination credits ONLY the unearned part');
$l = $mk(['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 36,
    'base_rent_monthly' => 31000, 'service_charge_monthly' => 0, 'security_deposit' => 93000, 'has_marketing_levy' => false]);
// bill August 2026 in full, on the 1st
$r = $billing->generateForLease($l->fresh(), CarbonImmutable::parse('2026-08-01'));
$inv = $r['invoice'];
qa_eq('August billed in full', 31000.00, (float) $inv->total);

// terminate on the 18th — 18 of 31 days earned
$term = app(LeaseTerminationService::class)->terminate($l->fresh(), [
    'termination_date' => '2026-08-18', 'reason' => 'QA early exit', 'cancel_open_invoices' => true, 'credit_unearned' => true,
]);
$notes = $term->getAttribute('termination_credit_notes');
qa_eq('one credit note raised', 1, $notes->count());
$cn = $notes->first();
$expected = round(31000 * (1 - 18 / 31), 2);
qa_eq('credit = the unearned 13/31 of the month', $expected, (float) $cn->total, 0.02);
qa_eq('lease is terminated', 'terminated', $term->status);
qa_eq('expiry pulled to the termination date', '2026-08-18', $term->expiry_date?->format('Y-m-d'));
qa_eq('the unit is freed', 'vacant', Unit::find($l->unit_id)->status);
qa_eq('the charge schedule is closed', 0, Charge::where('lease_id', $l->id)->where('is_active', true)->count());
$sept = $billing->planInvoiceForLease($l->fresh('charges'), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
qa_ok('nothing bills after termination', ! $sept['billable'], $sept['reason']);

qa_section('TERMINATION 2 — an EARNED past invoice is never cancelled');
$l2 = $mk(['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 36,
    'base_rent_monthly' => 20000, 'service_charge_monthly' => 0, 'security_deposit' => 60000, 'has_marketing_levy' => false]);
$july = $billing->generateForLease($l2->fresh(), CarbonImmutable::parse('2026-07-01'))['invoice'];
$aug = $billing->generateForLease($l2->fresh(), CarbonImmutable::parse('2026-08-01'))['invoice'];
app(LeaseTerminationService::class)->terminate($l2->fresh(), [
    'termination_date' => '2026-08-31', 'reason' => 'QA', 'cancel_open_invoices' => true, 'credit_unearned' => true]);
qa_ok('the fully-earned July invoice still stands', $july->fresh()->status !== 'cancelled', $july->fresh()->status);
qa_eq('…and still owes its balance', 20000.00, (float) $july->fresh()->balance);
qa_ok('the fully-earned August invoice still stands', $aug->fresh()->status !== 'cancelled', $aug->fresh()->status);
qa_eq('no unearned credit when the period ends exactly at termination', 0,
    CreditNote::where('lease_id', $l2->id)->count());

qa_section('TERMINATION 3 — a FUTURE-period invoice IS cancelled (nothing earned)');
$l3 = $mk(['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 36,
    'base_rent_monthly' => 20000, 'service_charge_monthly' => 0, 'security_deposit' => 60000, 'has_marketing_levy' => false]);
$oct = $billing->generateForLease($l3->fresh(), CarbonImmutable::parse('2026-10-01'))['invoice'];
app(LeaseTerminationService::class)->terminate($l3->fresh(), [
    'termination_date' => '2026-09-15', 'reason' => 'QA', 'cancel_open_invoices' => true, 'credit_unearned' => true]);
qa_eq('an invoice for a period entirely after the exit is cancelled', 'cancelled', $oct->fresh()->status);
qa_eq('…and its balance is zeroed', 0.00, (float) $oct->fresh()->balance);

qa_section('TERMINATION 4 — a PARTIALLY PAID future invoice is NOT silently cancelled');
$l4 = $mk(['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 36,
    'base_rent_monthly' => 20000, 'service_charge_monthly' => 0, 'security_deposit' => 60000, 'has_marketing_levy' => false]);
$nov = $billing->generateForLease($l4->fresh(), CarbonImmutable::parse('2026-11-01'))['invoice'];
$pay = Payment::create(['tenant_id' => $l4->tenant_id, 'asset_id' => $asset->id, 'amount' => 5000,
    'payment_date' => CarbonImmutable::parse('2026-09-01'), 'method' => 'bank_transfer', 'status' => 'captured',
    'reference' => 'QA-PP-'.uniqid()]);
$pay->invoices()->attach($nov->id, ['allocated_amount' => 5000]);
$nov->fresh()->recomputeTotals();
qa_eq('the invoice is part paid', 5000.00, (float) $nov->fresh()->paid_amount);
app(LeaseTerminationService::class)->terminate($l4->fresh(), [
    'termination_date' => '2026-09-15', 'reason' => 'QA', 'cancel_open_invoices' => true, 'credit_unearned' => true]);
qa_ok('a part-paid invoice survives termination (money must not be orphaned)',
    $nov->fresh()->status !== 'cancelled', $nov->fresh()->status);

qa_section('MOVE-OUT 1 — the final account nets deposit against arrears');
$l5 = $mk(['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 36,
    'base_rent_monthly' => 25000, 'service_charge_monthly' => 0, 'security_deposit' => 75000, 'has_marketing_levy' => false]);
DepositTransaction::create(['lease_id' => $l5->id, 'tenant_id' => $l5->tenant_id, 'asset_id' => $asset->id,
    'type' => 'receipt', 'amount' => 75000, 'transaction_date' => '2025-01-05', 'status' => 'recorded']);
$arrears = $billing->generateForLease($l5->fresh(), CarbonImmutable::parse('2026-06-01'))['invoice'];
qa_eq('deposit held', 75000.00, app(MoveOutStatementService::class)->depositHeld($l5->fresh()));
app(LeaseTerminationService::class)->terminate($l5->fresh(), [
    'termination_date' => '2026-08-31', 'reason' => 'QA', 'cancel_open_invoices' => false, 'credit_unearned' => true]);
$st = app(MoveOutStatementService::class)->for($l5->fresh(), CarbonImmutable::parse('2026-08-31'));
printf("  held=%s openAr=%s credit=%s net=%s residual=%s\n",
    number_format($st['deposit_held'], 2), number_format($st['open_ar'], 2),
    number_format($st['tenant_credit'], 2), number_format($st['net_to_tenant'], 2), number_format($st['residual_debt'], 2));
// SW-050 (2026-09-04): termination now bills the CONSUMED final cycle — August, terminated on
// its last day, is a full 25,000 the tenant occupied. Open AR = unpaid June + the final bill.
qa_eq('open AR = unpaid June rent + the consumed final August cycle', 50000.00, $st['open_ar']);
qa_eq('net to tenant = deposit - arrears (incl. the final cycle)', 25000.00, $st['net_to_tenant']);
qa_eq('no shortfall (deposit fully collected)', 0.00, $st['deposit_shortfall']);

$settled = app(SettleMoveOutService::class)->settle($l5->fresh(), [
    'settlement_date' => '2026-09-01', 'settle_arrears' => true,
    'deductions' => [['description' => 'Make-good of shopfront', 'amount' => 10000]],
]);
printf("  settlement: %s\n", json_encode(array_map(fn ($v) => is_object($v) ? class_basename($v).'#'.$v->id : $v, $settled)));
$arrears->refresh();
qa_eq('the arrears invoice is settled from the deposit', 0.00, (float) $arrears->balance);
qa_eq('…recorded as a deposit application, not a payment', 25000.00,
    (float) DepositApplication::where('invoice_id', $arrears->id)->sum('amount'));
$held = app(MoveOutStatementService::class)->depositHeld($l5->fresh());
qa_eq('deposit remaining after arrears + forfeit + refund is zero', 0.00, $held);
qa_eq('a forfeit was recorded for the deduction', 10000.00,
    (float) DepositTransaction::where('lease_id', $l5->id)->where('type', 'forfeit')->sum('amount'));
// 75,000 held − 50,000 arrears (June + SW-050's final August cycle) − 10,000 make-good.
qa_eq('the balance was refunded', 15000.00,
    (float) DepositTransaction::where('lease_id', $l5->id)->where('type', 'refund')->sum('amount'));

qa_section('MOVE-OUT 2 — refusals');
qa_refuses('deductions cannot exceed the deposit held',
    fn () => app(SettleMoveOutService::class)->settle($l5->fresh(), [
        'settlement_date' => '2026-09-02', 'deductions' => [['description' => 'Too much', 'amount' => 999999]]]),
    null, InvalidArgumentException::class);
$active = $mk(['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 36,
    'base_rent_monthly' => 10000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false]);
qa_refuses('a final account on a still-active lease is refused',
    fn () => app(SettleMoveOutService::class)->settle($active->fresh(), []), null, InvalidArgumentException::class);

qa_section('HOLDOVER');
$l6 = $mk(['commencement_date' => '2024-01-01', 'expiry_date' => '2026-07-31', 'term_months' => 31,
    'base_rent_monthly' => 40000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false]);
// rate_pct is a percentage OF the passing rent (market convention), default 150%
qa_refuses('holdover cannot start in a month the term already covered',
    fn () => app(ConvertLeaseToHoldoverService::class)->convert($l6->fresh(),
        ['effective_from' => '2026-07-01', 'rate_pct' => 125]), null, InvalidArgumentException::class);
qa_refuses('a zero/negative holdover rate is refused',
    fn () => app(ConvertLeaseToHoldoverService::class)->convert($l6->fresh(),
        ['effective_from' => '2026-08-01', 'rate_pct' => 0]), null, InvalidArgumentException::class);
$ho = app(ConvertLeaseToHoldoverService::class)->convert($l6->fresh(), [
    'effective_from' => '2026-08-01', 'rate_pct' => 125, 'reason' => 'QA holdover']);
$ho = $ho->fresh('charges');
printf("  holdover_from=%s rent=%s\n", $ho->holdover_from?->format('Y-m-d'), number_format((float) $ho->base_rent_monthly, 2));
qa_eq('holdover rent = 125% of the passing rent', 50000.00, (float) $ho->base_rent_monthly);
$hoPlan = $billing->planInvoiceForLease($ho, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
qa_ok('a holdover lease keeps billing past its expiry', $hoPlan['billable'], $hoPlan['reason'] ?? '');
qa_eq('…at the holdover rent, full month', 50000.00, $hoPlan['subtotal']);
qa_refuses('converting twice is refused',
    fn () => app(ConvertLeaseToHoldoverService::class)->convert($ho->fresh(),
        ['effective_from' => '2026-10-01', 'rate_pct' => 150]), null, InvalidArgumentException::class);
$notExpired = $mk(['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
    'base_rent_monthly' => 10000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false]);
qa_refuses('a lease that has not expired cannot hold over',
    fn () => app(ConvertLeaseToHoldoverService::class)->convert($notExpired->fresh(),
        ['rate_pct' => 150]), null, InvalidArgumentException::class);

qa_section('ACCOUNTING TIE-OUT after every lease act');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after terminations, credits, deposits and refunds');
$svc = app(BooksReconciliationService::class);
$tie = $svc->glTieOut();
printf("  AR gl=%s expected=%s | AP gl=%s expected=%s\n",
    number_format($tie['ar']['gl'], 2), number_format($tie['ar']['expected'], 2),
    number_format($tie['ap']['gl'], 2), number_format($tie['ap']['expected'], 2));
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($svc->glDriftDiscrepancies()));
qa_eq('deposits tie out to the deposits_held control', 0, count($svc->depositTieOutDiscrepancies()));

qa_summary();
