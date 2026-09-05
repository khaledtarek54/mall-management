<?php

require __DIR__.'/boot.php';
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillBouncedChequeFeeService;
use App\Services\CreditNoteService;
use App\Services\LateFeeService;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Services\PostDatedChequeService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\VoidInvoiceService;
use App\Services\VoidPaymentService;
use App\Services\WriteOffInvoiceService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$free = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$n = 0;
$billing = app(MonthlyBillingService::class);
$mk = function (float $rent = 100000, array $extra = []) use (&$n, $free, $asset, $tenant): Lease {
    $l = Lease::create(array_merge(['asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'unit_id' => $free[$n++]->id,
        'reference' => 'QA-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
        'base_rent_monthly' => $rent, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none'], $extra));
    LeaseCreationService::seedStandardCharges($l, $rent, 0, $l->commencement_date);

    return $l->fresh('charges');
};
$bill = fn (Lease $l, string $m) => $billing->generateForLease($l->fresh('charges'), CarbonImmutable::parse($m.'-01'))['invoice'];
$pay = function (Invoice $inv, float $amt, string $date = '2026-08-05'): Payment {
    $p = Payment::create(['tenant_id' => $inv->tenant_id, 'amount' => $amt, 'payment_date' => $date,
        'method' => 'bank_transfer', 'status' => 'captured', 'reference' => 'QA-'.uniqid()]);
    DB::transaction(function () use ($p, $inv, $amt) {
        $p->invoices()->sync([$inv->id => ['allocated_amount' => $amt]]);
        $p->assertInvoicesNotOverAllocated([$inv->id]);
    });
    $inv->fresh()->recomputeTotals();

    return $p->fresh();
};

qa_section('VOID INVOICE');
$l1 = $mk();
$i1 = $bill($l1, '2026-08');
$v = app(VoidInvoiceService::class)->void($i1->fresh(), 'QA void');
qa_eq('an unpaid invoice voids to cancelled', 'cancelled', $v->status);
qa_eq('…and claims no receivable', 0.00, (float) $v->balance);
$l2 = $mk();
$i2 = $bill($l2, '2026-08');
$pay($i2, 40000);
qa_refuses('an invoice with captured CASH cannot be voided',
    fn () => app(VoidInvoiceService::class)->void($i2->fresh(), 'QA'));
$l3 = $mk();
$i3 = $bill($l3, '2026-08');
$cn3 = CreditNote::create(['tenant_id' => $i3->tenant_id, 'lease_id' => $i3->lease_id, 'invoice_id' => $i3->id,
    'asset_id' => $i3->asset_id, 'issue_date' => '2026-08-10', 'reason' => 'adjustment', 'reason_notes' => 'QA', 'status' => 'draft',
    'subtotal' => 30000, 'vat_amount' => 0, 'total' => 30000, 'balance' => 30000]);
$cn3->items()->create(['description' => 'QA', 'quantity' => 1, 'unit_price' => 30000, 'amount' => 30000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 30000]);
app(CreditNoteService::class)->issue($cn3->fresh());
app(CreditNoteService::class)->applyToInvoice($cn3->fresh(), $i3->fresh());
qa_eq('a credit-settled invoice has no captured cash', 0.00, $i3->fresh()->capturedCashPaid());
qa_allows('…so it CAN still be voided (nothing to refund)',
    fn () => app(VoidInvoiceService::class)->void($i3->fresh(), 'QA'));
qa_eq('voiding releases the credit note back to the tenant', 30000.00, (float) $cn3->fresh()->balance);

qa_section('VOID PAYMENT');
$l4 = $mk();
$i4 = $bill($l4, '2026-08');
$p4 = $pay($i4, 60000);
qa_eq('invoice part paid', 60000.00, (float) $i4->fresh()->paid_amount);
app(VoidPaymentService::class)->void($p4->fresh(), 'QA reversal');
$i4->refresh();
qa_eq('voiding the payment re-opens the receivable', 0.00, (float) $i4->paid_amount);
qa_eq('…balance restored', 100000.00, (float) $i4->balance);
qa_ok('…and the invoice is no longer marked paid', in_array($i4->status, ['issued', 'overdue'], true), $i4->status);

qa_section('WRITE-OFF — full and partial');
$l5 = $mk(50000);
$i5 = $bill($l5, '2026-08');
$wo = app(WriteOffInvoiceService::class)->write($i5->fresh(), ['amount' => 20000, 'reason' => 'uneconomic_to_pursue', 'entry_date' => '2026-08-20']);
$i5->refresh();
qa_eq('a PARTIAL write-off books 20,000 of bad debt', 20000.00, (float) $wo->amount);
qa_eq('…and deliberately leaves the balance standing', 50000.00, (float) $i5->balance);
qa_ok('…so the invoice is still live', $i5->status !== 'written_off', $i5->status);
qa_refuses('writing off more than remains is refused',
    fn () => app(WriteOffInvoiceService::class)->write($i5->fresh(), ['amount' => 40000, 'reason' => 'uneconomic_to_pursue', 'entry_date' => '2026-08-20']));
$wo2 = app(WriteOffInvoiceService::class)->write($i5->fresh(), ['amount' => 30000, 'reason' => 'uneconomic_to_pursue', 'entry_date' => '2026-08-20']);
$i5->refresh();
qa_eq('writing off the remainder completes it', 'written_off', $i5->status);
qa_refuses('a fully written-off invoice cannot be written off again',
    fn () => app(WriteOffInvoiceService::class)->write($i5->fresh(), ['amount' => 1, 'reason' => 'uneconomic_to_pursue', 'entry_date' => '2026-08-20']));
app(WriteOffInvoiceService::class)->reverse($wo2->fresh());
qa_ok('reversing a write-off re-opens the debt', $i5->fresh()->status !== 'written_off', $i5->fresh()->status);

qa_section('LATE FEES');
$l6 = $mk(100000, ['late_fee_percent' => 2, 'late_fee_grace_days' => 5, 'late_fee_minimum' => 500]);
$i6 = $bill($l6, '2026-06');
$i6->forceFill(['due_date' => '2026-07-01', 'status' => 'overdue'])->saveQuietly();
$charged = app(LateFeeService::class)->applyTo($i6->fresh(), CarbonImmutable::parse('2026-08-19'));
qa_ok('a late fee is charged past the grace period', $charged);
$fee = Invoice::where('tenant_id', $i6->tenant_id)->whereHas('items', fn ($q) => $q->where('type', 'late_fee'))->latest('id')->first();
qa_ok('the fee is its OWN invoice, not a line on the overdue one', $fee !== null && $fee->id !== $i6->id, $fee?->number);
qa_eq('fee = 2% of the outstanding balance', 2000.00, (float) $fee->subtotal);
// Dated the day the fee is RAISED — which is the date passed to `applyTo()` above, not `now()`.
// Asserting `now()` only ever passed on the day this script was written (2026-08-19).
qa_eq('the fee invoice is dated the day it is RAISED, not the original period', '2026-08-19', $fee->issue_date->format('Y-m-d'));
qa_eq('a penalty carries no VAT', 0.00, (float) $fee->vat_amount);
qa_ok('re-running charges nothing more', ! app(LateFeeService::class)->applyTo($i6->fresh(), CarbonImmutable::parse('2026-08-20')));
$i6b = $bill($mk(100000, ['late_fee_percent' => 2, 'late_fee_grace_days' => 30, 'late_fee_minimum' => 500]), '2026-08');
$i6b->forceFill(['due_date' => '2026-08-15', 'status' => 'overdue'])->saveQuietly();
qa_ok('inside the grace period nothing is charged',
    ! app(LateFeeService::class)->applyTo($i6b->fresh(), CarbonImmutable::parse('2026-08-19')));
$paidInv = $bill($mk(20000, ['late_fee_percent' => 2, 'late_fee_grace_days' => 0]), '2026-06');
$pay($paidInv, 20000);
qa_ok('a settled invoice is never charged a late fee',
    ! app(LateFeeService::class)->applyTo($paidInv->fresh(), CarbonImmutable::parse('2026-08-19')));

qa_section('POSTING-DATE GUARD — a closed period refuses');
$period = AccountingPeriod::where('status', 'open')->orderBy('starts_on')->first();
if ($period) {
    printf("  closing period %s (%s → %s)\n", '#'.$period->id, $period->starts_on, $period->ends_on);
    $period->forceFill(['status' => 'closed'])->save();
    $closedDate = CarbonImmutable::parse($period->starts_on)->addDays(2)->toDateString();
    $l7 = $mk(30000);
    $i7 = $bill($l7, '2026-08');
    qa_refuses('a write-off dated into a CLOSED period is refused',
        fn () => app(WriteOffInvoiceService::class)->write($i7->fresh(), ['amount' => 1000, 'reason' => 'uneconomic_to_pursue', 'entry_date' => $closedDate]));
    qa_refuses('a payment dated into a CLOSED period is refused', function () use ($i7, $closedDate) {
        Payment::create(['tenant_id' => $i7->tenant_id, 'amount' => 100, 'payment_date' => $closedDate,
            'method' => 'cash', 'status' => 'captured', 'reference' => 'QA-'.uniqid()]);
    });
    $period->forceFill(['status' => 'open'])->save();
} else {
    echo "  (no open period found to close)\n";
}

qa_section('POST-DATED CHEQUES');
$l8 = $mk(45000);
$i8 = $bill($l8, '2026-08');
$pdc = PostDatedCheque::create(['tenant_id' => $i8->tenant_id, 'lease_id' => $l8->id, 'asset_id' => $asset->id,
    'reference' => 'PDC-QA-'.uniqid(), 'cheque_number' => 'QA'.random_int(100000, 999999), 'bank_name' => 'CIB', 'amount' => 45000,
    'cheque_date' => '2026-08-15', 'received_date' => '2026-07-01', 'status' => 'held', 'invoice_id' => $i8->id]);
qa_ok('a cheque held is not yet money', (float) $i8->fresh()->paid_amount === 0.00);
$svcPdc = app(PostDatedChequeService::class);
$svcPdc->deposit($pdc->fresh());
$cleared = $svcPdc->clear($pdc->fresh(), User::where('email', 'admin@mall.test')->first(), '2026-08-16');
$i8->refresh();
qa_eq('clearing the cheque settles the invoice', 45000.00, (float) $i8->paid_amount);
qa_eq('…via a real payment record', 1, Payment::whereHas('invoices', fn ($q) => $q->where('invoices.id', $i8->id))->count());
qa_eq('cheque marked cleared', 'cleared', $cleared->status);
qa_refuses('clearing twice is refused',
    fn () => $svcPdc->clear($pdc->fresh(), User::where('email', 'admin@mall.test')->first(), '2026-08-17'),
    null, Throwable::class);

$l9 = $mk(45000);
$i9 = $bill($l9, '2026-08');
$pdc2 = PostDatedCheque::create(['tenant_id' => $i9->tenant_id, 'lease_id' => $l9->id, 'asset_id' => $asset->id,
    'reference' => 'PDC-QA-'.uniqid(), 'cheque_number' => 'QA'.random_int(100000, 999999), 'bank_name' => 'CIB', 'amount' => 45000,
    'cheque_date' => '2026-08-15', 'received_date' => '2026-07-01', 'status' => 'held', 'invoice_id' => $i9->id]);
$svcPdc->deposit($pdc2->fresh());
$b = $svcPdc->bounce($pdc2->fresh());
qa_eq('a bounced cheque is marked bounced', 'bounced', $b->status);
qa_eq('…and settles nothing', 0.00, (float) $i9->fresh()->paid_amount);
qa_refuses('a bounced-cheque fee is REFUSED while unconfigured (never invented)',
    fn () => app(BillBouncedChequeFeeService::class)->bill($b->fresh()));
app(BillingSettings::class)->fill(['nsf_fee_amount' => 750])->save();
$feeInv = app(BillBouncedChequeFeeService::class)->bill($b->fresh());
qa_ok('a bounced-cheque fee invoice is raised', $feeInv !== null, $feeInv?->number);
qa_eq('…billed to the same tenant', $i9->tenant_id, $feeInv->tenant_id);
qa_eq('…at the configured amount', 750.00, (float) $feeInv->subtotal);
$again = app(BillBouncedChequeFeeService::class)->bill($b->fresh());
qa_eq('billing the same bounce twice returns the SAME invoice (idempotent)', $feeInv->id, $again->id);
qa_eq('…and the cheque still points at exactly one fee invoice', $feeInv->id, $b->fresh()->nsf_fee_invoice_id);

qa_section('ACCOUNTING TIE-OUT after every reversal');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after voids, write-offs, late fees and cheques');
$svc = app(BooksReconciliationService::class);
$tie = $svc->glTieOut();
printf("  AR gl=%s expected=%s delta=%s | AP delta=%s\n",
    number_format($tie['ar']['gl'], 2), number_format($tie['ar']['expected'], 2), $tie['ar']['delta'], $tie['ap']['delta']);
qa_eq('AR ties even with partial write-offs standing', 0.0, $tie['ar']['delta']);
qa_eq('no GL drift', 0, count($svc->glDriftDiscrepancies()));
qa_eq('deposits tie out', 0, count($svc->depositTieOutDiscrepancies()));

qa_summary();
