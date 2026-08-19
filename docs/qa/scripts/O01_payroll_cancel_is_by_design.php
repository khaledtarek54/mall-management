<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Services\Accounting\AccountResolver;
use App\Services\GeneratePayrollService;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$acct = fn (string $r) => app(AccountResolver::class)->id($r);

/*
 * NOT A DEFECT — recorded because the behaviour is surprising and worth an operator decision.
 *
 * `PayrollService::cancel()` cancels an APPROVED run with no guard, and the sweep then voids its
 * journal entry. Every peer refuses the equivalent (VendorBillService::cancel refuses a bill with
 * payments; VoidInvoiceService refuses an invoice with captured cash), so this looks at first like
 * an inconsistency.
 *
 * It is not. Module 24 states it as the intended correction path — "cancel the run — payslips and
 * their GL entries follow it" — and the peer comparison does not hold: a vendor bill has a SEPARATE
 * payment document that cancelling would strand, whereas a payroll has none (`paid_from` and
 * `net_paid` sit on the run itself). There is nothing to orphan, so voiding is the derived-ledger
 * model working as designed.
 *
 * The residual risk, which is the operator's to weigh rather than mine to change: there is no
 * distinction between APPROVED and ACTUALLY TRANSFERRED. Approval posts Cr Bank, so if the transfer
 * has genuinely left the account and someone then cancels, the books say it never did. Module 24
 * already flags a neighbouring wrinkle ("approving is irreversible in practice — cancel() voids the
 * entry, but the installments have already counted").
 */
qa_section('OBSERVED — an APPROVED payroll cancels, and the GL entry is voided with it (by design)');
$run = Payroll::create(['asset_id' => $asset->id, 'period_month' => '2026-08-01',
    'description' => 'QA payroll', 'status' => 'draft', 'paid_from' => 'bank']);
app(GeneratePayrollService::class)->generate($run->fresh());
app(PayrollService::class)->approve($run->fresh());
$run->refresh();
printf("  approved run %s · net paid %s\n", $run->number, number_format((float) $run->net_paid, 2));

Artisan::call('accounting:sync-ledger', ['--all' => true]);
$posted = JournalEntry::where('source_type', $run->getMorphClass())->where('source_id', $run->id)
    ->where('status', 'posted')->first();
qa_dump_entry($posted, 'payroll (approved)');
qa_ok('approval posts salaries against the bank', $posted !== null);
$bankBefore = qa_role_balance('bank');
$salBefore = qa_role_balance('salaries_expense');

qa_section('…now cancel it');
qa_allows('an approved payroll cancels with NO refusal', fn () => app(PayrollService::class)->cancel($run->fresh()));
qa_eq('status is cancelled', 'cancelled', $run->fresh()->status);
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$stillPosted = JournalEntry::where('source_type', $run->getMorphClass())->where('source_id', $run->id)
    ->where('status', 'posted')->exists();
qa_ok('the posted entry has been VOIDED', ! $stillPosted);
printf("  bank %s → %s · salaries expense %s → %s\n",
    number_format($bankBefore, 2), number_format(qa_role_balance('bank'), 2),
    number_format($salBefore, 2), number_format(qa_role_balance('salaries_expense'), 2));
qa_eq('the bank credit is reversed — money that WAS paid to staff', $bankBefore,
    qa_role_balance('bank') - (float) $run->net_paid, 0.02);
qa_ok('…and the salary expense is reversed out of the P&L',
    abs(qa_role_balance('salaries_expense') - ($salBefore - (float) $run->net_paid)) < 0.02);

qa_section('why the peer comparison does NOT apply');
qa_ok('VendorBillService::cancel refuses a bill with payments',
    str_contains(file_get_contents(base_path('app/Services/VendorBillService.php')), 'Cannot cancel a bill that has payments'));
qa_ok('VoidInvoiceService refuses an invoice with captured cash',
    str_contains(file_get_contents(base_path('app/Services/VoidInvoiceService.php')), 'captured'));
qa_ok('Payroll is registered NeverDeletable (a committed money record)',
    str_contains(file_get_contents(base_path('app/Models/Payroll.php')), 'NeverDeletable'));
qa_ok('…but a payroll has NO separate payment document to strand',
    ! collect(glob(base_path('app/Models/*.php')))->contains(fn ($f) => str_contains(basename($f), 'PayrollPayment')),
    'no PayrollPayment model — paid_from/net_paid sit on the run itself');
qa_ok('and module 24 names cancel as the correction path',
    str_contains(file_get_contents(base_path('docs/modules/24-hr-employees.md')),
        'cancel the run — payslips and their GL entries follow it'));

qa_summary();
