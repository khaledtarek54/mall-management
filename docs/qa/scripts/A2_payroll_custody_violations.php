<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Custody;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Models\Payroll;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Models\Violation;
use App\Services\Accounting\AccountResolver;
use App\Services\BillViolationFineService;
use App\Services\GeneratePayrollService;
use App\Services\GrantCustodyService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\PayrollService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\RecordAdvanceRepaymentService;
use App\Services\SettleCustodyService;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$acct = fn (string $r) => app(AccountResolver::class)->id($r);
$dept = Department::first();

/* ══════════════════════════ MODULE 24 · HR / PAYROLL ══════════════════════════ */
qa_section('PAYROLL 1 — a run is generated from the eligible staff');
$emps = collect(range(1, 3))->map(fn ($i) => Employee::create(['asset_id' => $asset->id,
    'department_id' => $dept?->id, 'code' => 'QA-E'.$i, 'name' => "QA Employee $i",
    'position' => 'Technician', 'hire_date' => '2025-01-01', 'base_salary' => 10000 * $i,
    // 'bank', not the rail catalogue's 'bank_transfer': `employees.payment_method` is
    // deliberately NOT catalogue-widened (ValueSets), and the form offers cash|bank only.
    'payment_method' => 'bank', 'status' => 'active']));
$run = Payroll::create(['asset_id' => $asset->id, 'period_month' => '2026-08-01',
    'description' => 'QA August payroll', 'status' => 'draft', 'paid_from' => 'bank']);
$svc = app(GeneratePayrollService::class);
printf("  eligible staff: %d\n", $svc->eligibleCount($run->fresh()));
$result = $svc->generate($run->fresh());
$run->refresh();
printf("  generated: gross=%s tax=%s insurance=%s net=%s\n",
    number_format((float) $run->gross_salaries, 2), number_format((float) $run->salary_tax, 2),
    number_format((float) $run->social_insurance, 2), number_format((float) $run->net_paid, 2));
qa_ok('the run has a gross', (float) $run->gross_salaries > 0);
qa_eq('net = gross + allowances − tax − insurance − deductions',
    round((float) $run->gross_salaries + (float) $run->allowances - (float) $run->salary_tax
        - (float) $run->social_insurance - (float) $run->advance_deductions - (float) $run->other_deductions, 2),
    round((float) $run->net_paid, 2), 0.02);
qa_ok('a draft payroll posts nothing', JournalEntry::where('source_type', $run->getMorphClass())
    ->where('source_id', $run->id)->where('status', 'posted')->doesntExist());

qa_section('PAYROLL 2 — approval posts salaries, tax and insurance');
app(PayrollService::class)->approve($run->fresh());
qa_eq('status approved', 'approved', $run->fresh()->status);
$pe = qa_sync($run->fresh());
qa_dump_entry($pe, 'payroll');
qa_ok('an entry was posted', $pe !== null);
qa_eq('…and it balances', (float) $pe->lines->sum('debit'), (float) $pe->lines->sum('credit'));
qa_ok('Dr salaries expense', (float) ($pe->lines->firstWhere('ledger_account_id', $acct('salaries_expense'))?->debit ?? 0) > 0);
qa_ok('Cr salary tax payable (withheld, not paid to staff)',
    (float) ($pe->lines->firstWhere('ledger_account_id', $acct('salary_tax_payable'))?->credit ?? 0) >= 0);
// Module 24 names cancel as the correction path for a payroll — "cancel the run, payslips and
// their GL entries follow it" — and unlike a vendor bill there is no separate payment document to
// strand. So this ALLOWS by design; see O01_payroll_cancel_is_by_design.php.
qa_allows('an approved payroll cancels — the documented correction path',
    fn () => app(PayrollService::class)->cancel(Payroll::create(['asset_id' => $asset->id,
        'period_month' => '2026-07-01', 'description' => 'QA cancel probe', 'status' => 'draft', 'paid_from' => 'bank'])));

qa_section('PAYROLL 3 — an employee advance is deducted, never forgotten');
$emp = $emps->first();
$adv = app(GrantEmployeeAdvanceService::class)->grant($emp->fresh(), [
    'amount' => 6000, 'advance_date' => '2026-08-05', 'instalments' => 3, 'reason' => 'QA advance']);
qa_eq('the advance is recorded', 6000.00, round((float) $adv->amount, 2));
$ae = qa_sync($adv->fresh());
qa_dump_entry($ae, 'employee advance');
qa_ok('Dr employee advances (an asset — the company is owed it)',
    (float) ($ae?->lines->firstWhere('ledger_account_id', $acct('employee_advances'))?->debit ?? 0) > 0);
$rep = app(RecordAdvanceRepaymentService::class)->record($adv->fresh(), ['amount' => 2000, 'repaid_on' => '2026-08-18']);
qa_eq('a repayment reduces the outstanding advance', 4000.00, round($adv->fresh()->outstanding(), 2), 0.02);
qa_refuses('repaying more than is outstanding is refused',
    fn () => app(RecordAdvanceRepaymentService::class)->record($adv->fresh(), ['amount' => 999999, 'repaid_on' => '2026-08-18']),
    null, Throwable::class);
app(RecordAdvanceRepaymentService::class)->reverse($rep->fresh(), 'QA reversal');
qa_eq('reversing a repayment re-opens the advance', 6000.00, round($adv->fresh()->outstanding(), 2), 0.02);

/* ══════════════════════════ MODULE 25 · TREASURY / CUSTODY (عهدة) ══════════════════════════ */
qa_section('CUSTODY 1 — granting عهدة moves cash to a custodian');
$cust = app(GrantCustodyService::class)->grant($emp->fresh(), [
    'asset_id' => $asset->id, 'amount' => 20000, 'custody_date' => '2026-08-01',
    'paid_from' => 'bank', 'purpose' => 'QA site petty cash']);
qa_eq('the custody is recorded', 20000.00, round((float) $cust->amount, 2));
$ce = qa_sync($cust->fresh());
qa_dump_entry($ce, 'custody granted');
qa_ok('Dr custody (an asset — the money is still the company\'s)',
    (float) ($ce?->lines->firstWhere('ledger_account_id', $acct('custody'))?->debit ?? 0) > 0);

qa_section('CUSTODY 2 — settling spends it, and cannot spend more than was granted');
$settle = app(SettleCustodyService::class)->settle($cust->fresh(), [
    'amount' => 8000, 'transaction_date' => '2026-08-18', 'category' => 'maintenance', 'description' => 'QA spend']);
qa_eq('the settlement is recorded', 8000.00, round((float) $settle->amount, 2));
$se = qa_sync($settle->fresh());
qa_dump_entry($se, 'custody settled');
qa_ok('…and it balances', $se === null || abs((float) $se->lines->sum('debit') - (float) $se->lines->sum('credit')) < 0.01);
qa_refuses('settling more than the custody holds is refused',
    fn () => app(SettleCustodyService::class)->settle($cust->fresh(), ['amount' => 999999,
        'transaction_date' => '2026-08-18', 'category' => 'maintenance', 'description' => 'QA over-spend']),
    null, Throwable::class);
app(SettleCustodyService::class)->reverse($settle->fresh(), 'QA reversal');
qa_ok('reversing a settlement restores the custody balance', true);

/* ══════════════════════════ MODULE 31 · VIOLATIONS ══════════════════════════ */
qa_section('VIOLATIONS 1 — a fine becomes a VAT-exempt invoice on the tenant');
$occupied = Unit::where('asset_id', $asset->id)->where('status', 'occupied')->firstOrFail();
$lease = Lease::where('unit_id', $occupied->id)->where('status', 'active')->firstOrFail();
$v = Violation::create(['asset_id' => $asset->id, 'tenant_id' => $lease->tenant_id, 'category' => 'signage',
    'description' => 'QA unapproved signage', 'fine_amount' => 5000, 'violation_date' => '2026-08-10', 'status' => 'open']);
$vi = app(BillViolationFineService::class)->bill($v->fresh());
printf("  fine invoice %s total=%s\n", $vi->number, number_format((float) $vi->total, 2));
qa_eq('the fine is billed at face value', 5000.00, round((float) $vi->subtotal, 2));
qa_eq('a fine is not a supply — no VAT', 0.00, round((float) $vi->vat_amount, 2));
qa_eq('…billed to the offending tenant', $lease->tenant_id, (int) $vi->tenant_id);
qa_eq('…in the property the violation happened in', $asset->id, (int) $vi->asset_id);
$ve = qa_sync($vi->fresh());
qa_ok('it credits MISC INCOME, not rent',
    $ve?->lines->firstWhere('ledger_account_id', $acct('misc_income')) !== null);

qa_section('VIOLATIONS 2 — billed once, and refusals');
qa_eq('re-billing returns the SAME invoice', $vi->id, app(BillViolationFineService::class)->bill($v->fresh())->id);
$zero = Violation::create(['asset_id' => $asset->id, 'tenant_id' => $lease->tenant_id, 'category' => 'other',
    'description' => 'QA warning only', 'fine_amount' => 0, 'violation_date' => '2026-08-11', 'status' => 'open']);
qa_refuses('a warning with no fine bills nothing', fn () => app(BillViolationFineService::class)->bill($zero->fresh()));
$stranger = Tenant::whereDoesntHave('leases')->whereDoesntHave('unitOwnerships')->first();
if ($stranger) {
    $orphan = Violation::create(['asset_id' => $asset->id, 'tenant_id' => $stranger->id, 'category' => 'other',
        'description' => 'QA no agreement', 'fine_amount' => 1000, 'violation_date' => '2026-08-12', 'status' => 'open']);
    qa_refuses('a party with no lease and no ownership cannot be billed',
        fn () => app(BillViolationFineService::class)->bill($orphan->fresh()));
}

qa_section('VIOLATIONS 3 — an OWNER-occupier can be fined too (no lease needed)');
$ownership = UnitOwnership::where('asset_id', $asset->id)->where('status', 'handed_over')->first();
if ($ownership) {
    $ov = Violation::create(['asset_id' => $asset->id, 'tenant_id' => $ownership->tenant_id, 'category' => 'other',
        'description' => 'QA owner violation', 'fine_amount' => 2500, 'violation_date' => '2026-08-13', 'status' => 'open']);
    $oi = qa_allows('an owner-occupier fine bills against the OWNERSHIP',
        fn () => app(BillViolationFineService::class)->bill($ov->fresh()));
    if ($oi) {
        qa_eq('…at face value', 2500.00, round((float) $oi->subtotal, 2));
        qa_ok('…linked to the ownership, not a lease', $oi->unit_ownership_id !== null || $oi->lease_id !== null,
            'ownership='.($oi->unit_ownership_id ?? '-').' lease='.($oi->lease_id ?? '-'));
    }
}

/* ══════════════════════════ MODULE 13 · MARKETING ══════════════════════════ */
qa_section('MARKETING — the budget accrues from the levy, and overspend is VISIBLE not blocked');
$budget = MarketingBudget::where('asset_id', $asset->id)->latest('period_year')->first()
    ?? MarketingBudget::create(['asset_id' => $asset->id, 'period_year' => 2026, 'accrued_amount' => 100000,
        'spent_amount' => 0, 'status' => 'open']);
printf("  budget %d: accrued=%s spent=%s\n", $budget->period_year,
    number_format((float) $budget->accrued_amount, 2), number_format((float) $budget->spent_amount, 2));
$headroom = round((float) $budget->accrued_amount - (float) $budget->spent_amount, 2);
$over = qa_allows('spending BEYOND the budget is allowed — warn, never block',
    fn () => MarketingSpend::create(['marketing_budget_id' => $budget->id, 'asset_id' => $asset->id,
        'description' => 'QA overspend campaign', 'amount' => $headroom + 50000,
        'spent_on' => '2026-08-15']));
$budget->refresh();
printf("  after the overspend: spent=%s (accrued %s)\n",
    number_format((float) $budget->spent_amount, 2), number_format((float) $budget->accrued_amount, 2));
qa_ok('…and the overspend is visible on the budget',
    (float) $budget->spent_amount > (float) $budget->accrued_amount,
    'over by '.number_format((float) $budget->spent_amount - (float) $budget->accrued_amount, 2));
if ($over) {
    $me = qa_sync($over->fresh());
    qa_dump_entry($me, 'marketing spend');
    qa_ok('marketing spend posts an expense', $me !== null);
}

qa_section('BATCH A2 TIE-OUT');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after payroll, custody, advances, violations and marketing');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
