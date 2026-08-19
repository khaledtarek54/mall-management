<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Reconciliation\BooksReconciliationService;

qa_section('Baseline — QA dataset shape');
foreach ([Asset::class, Unit::class, Tenant::class, Lease::class, Invoice::class, Payment::class, CreditNote::class, VendorBill::class, Expense::class, DepositTransaction::class, PostDatedCheque::class, Vendor::class, JournalEntry::class] as $m) {
    printf("  %-28s %6d\n", class_basename($m), $m::count());
}
echo "\n  Assets:\n";
foreach (Asset::withoutGlobalScopes()->get() as $a) {
    printf("    #%d %-18s code=%-6s units=%d\n", $a->id, $a->name, $a->code, $a->units()->count());
}

echo "\n  Lease statuses: ";
print_r(Lease::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->toArray());
echo '  Invoice statuses: ';
print_r(Invoice::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->toArray());
echo '  Unit statuses: ';
print_r(Unit::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->toArray());

qa_section('Accounting baseline');
qa_assert_tb('whole book');
printf("  AR control (accounts_receivable): %s\n", number_format(qa_role_balance('accounts_receivable'), 2));
printf("  Sum of invoice balances (issued-ish): %s\n", number_format((float) Invoice::whereNotIn('status', ['draft', 'cancelled'])->sum('balance'), 2));
printf("  deposits_held: %s\n", number_format(qa_role_balance('deposits_held'), 2));
printf("  accounts_payable: %s\n", number_format(qa_role_balance('accounts_payable'), 2));
qa_summary();

qa_section('Project reconciliation service (the app own tie-out)');
$svc = app(BooksReconciliationService::class);
$tie = $svc->glTieOut();
print_r($tie);
echo "\n  glDrift discrepancies: ".count($svc->glDriftDiscrepancies())."\n";
echo '  deposit tie-out discrepancies: '.count($svc->depositTieOutDiscrepancies())."\n";
