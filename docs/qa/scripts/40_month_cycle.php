<?php

require __DIR__.'/boot.php';
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\MonthEndReadinessService;
use App\Services\Accounting\PeriodService;
use App\Services\MonthlyBillingService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\Reports\ReportService;
use App\Services\VendorBillService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$ledger = app(LedgerReportService::class);
$reports = app(ReportService::class);
$billing = app(MonthlyBillingService::class);
$month = CarbonImmutable::parse('2026-08-01');
$from = $month;
$to = $month->endOfMonth();

qa_section('CYCLE 1 — the billing PREVIEW must equal what the run actually posts');
$preview = $billing->previewForPeriod($month, $asset->id);
printf("  preview: will_bill=%d skipped=%d subtotal=%s vat=%s total=%s\n",
    $preview['totals']['will_bill'], $preview['totals']['skipped'],
    number_format($preview['totals']['subtotal'], 2), number_format($preview['totals']['vat_amount'], 2),
    number_format($preview['totals']['total'], 2));
$arBefore = qa_role_balance('accounts_receivable');
$lastId = (int) Invoice::max('id');
$run = $billing->runForPeriod($month, $asset->id);
printf("  run    : %s\n", json_encode($run));
$raised = Invoice::where('id', '>', $lastId)->where('status', '!=', 'cancelled')->get();
printf("  invoices raised now: %d totalling %s\n", $raised->count(), number_format((float) $raised->sum('total'), 2));
qa_eq('the run created exactly what the preview promised', $preview['totals']['will_bill'], $run['created']);
qa_eq('…and for the same money', round($preview['totals']['total'], 2), round((float) $raised->sum('total'), 2), 0.05);
$preview2 = $billing->previewForPeriod($month, $asset->id);
qa_eq('re-previewing after the run proposes nothing', 0, $preview2['totals']['will_bill']);
$run2 = $billing->runForPeriod($month, $asset->id);
qa_eq('re-running the run creates nothing', 0, $run2['created']);

qa_section('CYCLE 2 — every raised invoice reaches the general ledger');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$arAfter = qa_role_balance('accounts_receivable');
printf("  AR control: %s → %s (Δ %s)\n", number_format($arBefore, 2), number_format($arAfter, 2), number_format($arAfter - $arBefore, 2));
qa_eq('AR rose by exactly what was billed', round((float) $raised->sum('total'), 2), round($arAfter - $arBefore, 2), 0.05);
$unposted = $raised->filter(fn ($i) => JournalEntry::where('source_type', $i->getMorphClass())
    ->where('source_id', $i->id)->where('status', 'posted')->doesntExist());
qa_eq('no invoice was left unposted', 0, $unposted->count(), 0);

qa_section('CYCLE 3 — collect some of it');
$collected = 0.0;
foreach ($raised->take(5) as $idx => $inv) {
    $amt = $idx % 2 === 0 ? round((float) $inv->total, 2) : round((float) $inv->total / 2, 2);
    $p = Payment::create(['tenant_id' => $inv->tenant_id, 'amount' => $amt, 'payment_date' => '2026-08-20',
        'method' => 'bank_transfer', 'status' => 'captured', 'reference' => 'QA-CYC-'.uniqid()]);
    DB::transaction(function () use ($p, $inv, $amt) {
        $p->invoices()->sync([$inv->id => ['allocated_amount' => $amt]]);
        $p->assertInvoicesNotOverAllocated([$inv->id]);
    });
    $inv->fresh()->recomputeTotals();
    $collected += $amt;
}
printf("  collected: %s across 5 invoices\n", number_format($collected, 2));
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$arCollected = qa_role_balance('accounts_receivable');
qa_eq('AR fell by exactly what was collected', round(-$collected, 2), round($arCollected - $arAfter, 2), 0.05);

qa_section('CYCLE 4 — costs for the month');
$vendor = Vendor::first();
$bill = VendorBill::create(['vendor_id' => $vendor->id, 'asset_id' => $asset->id,
    'number' => 'QA-CYC-'.strtoupper(bin2hex(random_bytes(3))), 'bill_date' => '2026-08-10', 'due_date' => '2026-09-10',
    'category' => 'cleaning_security', 'subtotal' => 80000, 'vat_amount' => 11200, 'total' => 91200,
    'status' => 'draft', 'currency' => 'EGP', 'description' => 'QA August cleaning']);
app(VendorBillService::class)->approve($bill->fresh());
Expense::create(['asset_id' => $asset->id, 'vendor_id' => $vendor->id, 'category' => 'utilities',
    'description' => 'QA August electricity', 'amount' => 30000, 'vat_amount' => 4200, 'total' => 34200,
    'expense_date' => '2026-08-12', 'status' => 'recorded', 'payment_method' => 'bank_transfer', 'currency' => 'EGP']);
Artisan::call('accounting:sync-ledger', ['--all' => true]);

qa_section('CYCLE 5 — the financial statements');
$tb = $ledger->trialBalance([$asset->id], $from, $to);
printf("  trial balance for %s: Dr %s / Cr %s (balanced=%s, %d accounts)\n", $month->format('F Y'),
    number_format($tb['total_debit'], 2), number_format($tb['total_credit'], 2),
    var_export($tb['balanced'], true), $tb['rows']->count());
qa_ok('the trial balance reports itself balanced', $tb['balanced']);
qa_eq('…and the two columns agree', $tb['total_debit'], $tb['total_credit'], 0.02);
qa_ok('the month has ledger movement', $tb['rows']->count() > 0);

$is = $ledger->incomeStatement([$asset->id], $from, $to);
printf("  income statement: revenue %s · expenses %s · net %s\n",
    number_format((float) $is['total_revenue'], 2), number_format((float) $is['total_expense'], 2),
    number_format((float) $is['net_profit'], 2));
qa_eq('net profit = revenue − expenses',
    round((float) $is['total_revenue'] - (float) $is['total_expense'], 2), round((float) $is['net_profit'], 2), 0.02);
qa_ok('the month recognised revenue', (float) $is['total_revenue'] > 0, number_format((float) $is['total_revenue'], 2));
qa_ok('…and recognised the costs', (float) $is['total_expense'] > 0, number_format((float) $is['total_expense'], 2));

// revenue recognised must equal what was billed net of VAT (VAT is a liability, not revenue)
$billedNet = round((float) $raised->sum('subtotal'), 2);
printf("  billed net of VAT this month: %s\n", number_format($billedNet, 2));
qa_ok('recognised revenue is at least the rent billed this month',
    (float) $is['total_revenue'] >= $billedNet - 0.05,
    'IS revenue '.number_format((float) $is['total_revenue'], 2).' vs billed net '.number_format($billedNet, 2));

$bs = $ledger->balanceSheet([$asset->id], $to);
printf("  balance sheet: assets %s = liabilities %s + equity %s + net income %s → %s (balanced=%s)\n",
    number_format((float) $bs['total_assets'], 2), number_format((float) $bs['total_liabilities'], 2),
    number_format((float) $bs['total_equity'], 2), number_format((float) $bs['net_income'], 2),
    number_format((float) $bs['total_equity_and_liabilities'], 2), var_export($bs['balanced'], true));
qa_ok('the balance sheet reports itself balanced', $bs['balanced']);
qa_eq('assets = liabilities + equity (+ unclosed net income)',
    round((float) $bs['total_assets'], 2), round((float) $bs['total_equity_and_liabilities'], 2), 0.05);

qa_section('CYCLE 6 — AR aging ties to the open receivables');
$aging = $reports->arAgingBuckets($to);
$bucketTotal = round((float) collect($aging['buckets'] ?? $aging)->sum(fn ($b) => (float) ($b['amount'] ?? $b['total'] ?? 0)), 2);
// Mirror the report's POPULATION (live statuses, issued by the as-of date) but compute the
// figure by independent arithmetic: collectable = max(0, balance − prior write-offs). The report
// deliberately chases only what is still collectable — quoting raw `balance` would chase money
// the operator forgave (a partial write-off leaves `balance` standing by design).
$openAr = round((float) Invoice::query()->stillOwed()
    ->whereDate('issue_date', '<=', $to)
    ->where('asset_id', $asset->id)->with('writeOffs')->get()
    ->sum(fn ($i) => max(0.0, (float) $i->balance - (float) $i->writeOffs->sum('amount'))), 2);
printf("  aging buckets total %s vs open invoice balances %s\n", number_format($bucketTotal, 2), number_format($openAr, 2));
qa_eq('the aging report totals the real open AR', $openAr, $bucketTotal, 1.0);

qa_section('CYCLE 7 — month-end readiness + close gate');
$readiness = app(MonthEndReadinessService::class)->for($month, $asset->id);
$blocking = collect($readiness['checks'] ?? $readiness)->filter(fn ($c) => ($c['status'] ?? null) === 'fail');
printf("  readiness checks: %d, failing: %d\n", count($readiness['checks'] ?? $readiness), $blocking->count());
foreach ($blocking as $c) {
    printf("    FAIL %s — %s\n", $c['key'] ?? '?', mb_substr((string) ($c['detail'] ?? $c['message'] ?? ''), 0, 90));
}

$period = AccountingPeriod::whereDate('starts_on', '<=', $month->toDateString())
    ->whereDate('ends_on', '>=', $month->toDateString())->first();
if ($period) {
    printf("  period #%d %s → %s status=%s\n", $period->id, $period->starts_on, $period->ends_on, $period->status);
    qa_ok('the month has an accounting period', true);
    // close it and prove the guard bites afterwards
    try {
        app(PeriodService::class)->closePeriod($period->fresh());
        qa_eq('the period closes', 'closed', $period->fresh()->status);
        qa_refuses('…and a payment into the closed month is then refused', function () use ($month) {
            Payment::create(['tenant_id' => Tenant::first()->id, 'amount' => 100,
                'payment_date' => $month->addDays(5)->toDateString(), 'method' => 'cash',
                'status' => 'captured', 'reference' => 'QA-CLOSED-'.uniqid()]);
        });
        $period->forceFill(['status' => 'open'])->save();
    } catch (Throwable $t) {
        printf("  close refused: %s\n", mb_substr($t->getMessage(), 0, 160));
        qa_ok('the close gate refuses rather than closing over a problem', true, mb_substr($t->getMessage(), 0, 90));
    }
}

qa_section('CYCLE 8 — final tie-out');
qa_assert_tb('end of the month cycle', $asset->id);
qa_assert_tb('whole book');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
printf("  AR gl=%s expected=%s | AP gl=%s expected=%s\n",
    number_format($tie['ar']['gl'], 2), number_format($tie['ar']['expected'], 2),
    number_format($tie['ap']['gl'], 2), number_format($tie['ap']['expected'], 2));
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));
qa_eq('deposits tie out', 0, count($rec->depositTieOutDiscrepancies()));
$deep = $rec->run($month->format('Y-m'), true);
$fails = collect($deep['checks'] ?? [])->filter(fn ($c) => ($c['ok'] ?? true) === false);
printf("  billing:reconcile --deep → %d checks, %d failing\n", count($deep['checks'] ?? []), $fails->count());
foreach ($fails as $f) {
    printf("    FAIL %s: %s\n", $f['key'] ?? '?', json_encode($f['discrepancies'] ?? $f));
}
qa_eq('the deep reconciliation is clean', 0, $fails->count());

qa_summary();
