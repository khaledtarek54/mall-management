<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use App\Models\Unit;
use App\Services\ApplyDepositToInvoiceService;
use App\Services\ApplyTenantCreditService;
use App\Services\CreditNoteService;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$free = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$n = 0;
$billing = app(MonthlyBillingService::class);
$mk = function (float $rent = 100000) use (&$n, $free, $asset, $tenant): Lease {
    $l = Lease::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'unit_id' => $free[$n++]->id,
        'reference' => 'QA-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
        'base_rent_monthly' => $rent, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
        'billing_frequency' => 'monthly', 'billing_day' => 1, 'payment_terms_days' => 7, 'escalation_type' => 'none']);
    LeaseCreationService::seedStandardCharges($l, $rent, 0, $l->commencement_date);

    return $l->fresh('charges');
};
$bill = fn (Lease $l, string $m) => $billing->generateForLease($l->fresh('charges'), CarbonImmutable::parse($m.'-01'))['invoice'];
$pay = function (Invoice $inv, float $amt, string $date = '2026-08-05'): Payment {
    $p = Payment::create(['tenant_id' => $inv->tenant_id, 'amount' => $amt,
        'payment_date' => $date, 'method' => 'bank_transfer', 'status' => 'captured', 'reference' => 'QA-'.uniqid()]);
    $p->assertInvoicesNotOverAllocated([$inv->id]);
    $p->invoices()->attach($inv->id, ['allocated_amount' => $amt]);
    $inv->fresh()->recomputeTotals();

    return $p->fresh();
};

function qa_make_credit_note(Invoice $inv, float $amount, string $why): CreditNote
{
    $cn = CreditNote::create([
        'tenant_id' => $inv->tenant_id, 'lease_id' => $inv->lease_id, 'invoice_id' => $inv->id, 'asset_id' => $inv->asset_id,
        'issue_date' => '2026-08-10', 'reason' => 'adjustment', 'reason_notes' => $why, 'status' => 'draft',
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount, 'balance' => $amount,
    ]);
    $cn->items()->create(['description' => $why, 'quantity' => 1, 'unit_price' => $amount,
        'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount]);

    return $cn->fresh();
}

qa_section('AR 1 — channel 1: cash payments');
$l1 = $mk(100000);
$i1 = $bill($l1, '2026-08');
qa_eq('invoice raised at 100,000', 100000.00, (float) $i1->total);
qa_eq('status issued', 'issued', $i1->status);
$pay($i1, 40000);
$i1->refresh();
qa_eq('part payment lands', 40000.00, (float) $i1->paid_amount);
qa_eq('balance falls', 60000.00, (float) $i1->balance);
qa_eq('status becomes partially_paid', 'partially_paid', $i1->status);
$pay($i1, 60000);
$i1->refresh();
qa_eq('full settlement', 100000.00, (float) $i1->paid_amount);
qa_eq('balance zero', 0.00, (float) $i1->balance);
qa_eq('status paid', 'paid', $i1->status);

qa_section('AR 2 — over-allocation is refused on BOTH guards');
$l2 = $mk(50000);
$i2 = $bill($l2, '2026-08');
$pay($i2, 30000);
// mirrors CreatePayment::afterCreate — sync + assert INSIDE one transaction, so a refusal rolls back
qa_refuses('a payment that would over-settle an invoice is refused', function () use ($i2) {
    $p = Payment::create(['tenant_id' => $i2->tenant_id, 'amount' => 40000,
        'payment_date' => '2026-08-06', 'method' => 'cash', 'status' => 'captured', 'reference' => 'QA-'.uniqid()]);
    DB::transaction(function () use ($p, $i2) {
        $p->invoices()->sync([$i2->id => ['allocated_amount' => 40000]]);
        $p->assertInvoicesNotOverAllocated([$i2->id]);
    });
});
qa_eq('the refused allocation was ROLLED BACK, not left behind', 0,
    DB::table('invoice_payment')->where('invoice_id', $i2->id)->where('allocated_amount', 40000)->count());
qa_eq('…and the invoice is untouched', 20000.00, (float) $i2->fresh()->balance);

qa_section('AR 3 — channel 2: credit notes');
$l3 = $mk(80000);
$i3 = $bill($l3, '2026-08');
$cn = qa_make_credit_note($i3, 20000, 'QA goodwill');
printf("  credit note %s total=%s status=%s\n", $cn->number, number_format((float) $cn->total, 2), $cn->status);
app(CreditNoteService::class)->issue($cn->fresh());
app(CreditNoteService::class)->applyToInvoice($cn->fresh(), $i3->fresh());
$i3->refresh();
qa_eq('credit_applied_amount rises', 20000.00, (float) $i3->credit_applied_amount);
qa_eq('paid_amount counts the credit', 20000.00, (float) $i3->paid_amount);
qa_eq('balance falls by the credit', 60000.00, (float) $i3->balance);
qa_eq('capturedCashPaid excludes it (nothing to refund)', 0.00, $i3->capturedCashPaid());

qa_section('AR 4 — a payment cannot over-settle what a credit note already paid');
qa_refuses('the credit note is counted by the over-allocation guard', function () use ($i3) {
    $p = Payment::create(['tenant_id' => $i3->tenant_id, 'amount' => 80000,
        'payment_date' => '2026-08-11', 'method' => 'cash', 'status' => 'captured', 'reference' => 'QA-'.uniqid()]);
    DB::transaction(function () use ($p, $i3) {
        $p->invoices()->sync([$i3->id => ['allocated_amount' => 80000]]);
        $p->assertInvoicesNotOverAllocated([$i3->id]);
    });
});
$p3 = Payment::create(['tenant_id' => $i3->tenant_id, 'amount' => 80000,
    'payment_date' => '2026-08-11', 'method' => 'card', 'status' => 'initiated', 'reference' => 'QA-'.uniqid()]);
$p3->invoices()->attach($i3->id, ['allocated_amount' => 80000]);
$p3->refitAllocationsToBalance();
$fitted = (float) DB::table('invoice_payment')->where('payment_id', $p3->id)->where('invoice_id', $i3->id)->value('allocated_amount');
qa_eq('the GATEWAY path clamps instead of throwing', 60000.00, $fitted);
qa_ok('…and the surplus stays unallocated on the payment (a recoverable overpayment)',
    round((float) $p3->amount - $fitted, 2) === 20000.00, 'unallocated = '.number_format((float) $p3->amount - $fitted, 2));

qa_section('AR 5 — channel 3: on-account tenant credit');
$l4 = $mk(70000);
$i4 = $bill($l4, '2026-08');
// The app REFUSES a receipt with no allocation (CreatePayment::guardHasAllocation), so on-account
// credit can only arise as an OVERPAYMENT: pay 30,000 against a 5,000 invoice, 25,000 stays on account.
$small = $bill($mk(5000), '2026-07');
$adv = Payment::create(['tenant_id' => $i4->tenant_id, 'amount' => 30000,
    'payment_date' => '2026-07-01', 'method' => 'bank_transfer', 'status' => 'captured', 'reference' => 'QA-ADV-'.uniqid()]);
$adv->invoices()->attach($small->id, ['allocated_amount' => 5000]);
$small->fresh()->recomputeTotals();
printf("  overpayment leaves on-account credit: %s\n",
    number_format($i4->tenant->fresh()->creditBalance([$asset->id]), 2));
qa_eq('the surplus reads as tenant credit for the property', 25000.00,
    $i4->tenant->fresh()->creditBalance([$asset->id]));
$applied = app(ApplyTenantCreditService::class)->applyToInvoice($i4->fresh(), 25000);
$i4->refresh();
qa_eq('tenant credit settles AR', 25000.00, (float) TenantCreditApplication::where('invoice_id', $i4->id)->sum('amount'));
qa_eq('paid_amount counts it', 25000.00, (float) $i4->paid_amount);
qa_eq('balance falls', 45000.00, (float) $i4->balance);
qa_eq('capturedCashPaid excludes it', 0.00, $i4->capturedCashPaid());

qa_section('AR 6 — channel 4: netted security deposit');
$l5 = $mk(60000);
$i5 = $bill($l5, '2026-08');
DepositTransaction::create(['lease_id' => $l5->id, 'tenant_id' => $l5->tenant_id, 'asset_id' => $asset->id,
    'type' => 'receipt', 'amount' => 60000, 'transaction_date' => '2026-01-05', 'status' => 'recorded']);
$l5->update(['status' => 'terminated', 'expiry_date' => '2026-08-31']);
$r = app(ApplyDepositToInvoiceService::class)->settleOpenAr($l5->fresh(), CarbonImmutable::parse('2026-09-01'));
$i5->refresh();
printf("  settleOpenAr: %s\n", json_encode($r));
qa_eq('the deposit settles the invoice', 60000.00, (float) DepositApplication::where('invoice_id', $i5->id)->sum('amount'));
qa_eq('paid_amount counts it', 60000.00, (float) $i5->paid_amount);
qa_eq('balance zero', 0.00, (float) $i5->balance);
qa_eq('capturedCashPaid excludes it', 0.00, $i5->capturedCashPaid());

qa_section('AR 7 — all four channels on ONE invoice sum exactly to the total');
$l6 = $mk(100000);
$i6 = $bill($l6, '2026-08');
$pay($i6, 25000, '2026-08-05');                                  // cash
$cn6 = qa_make_credit_note($i6, 25000, 'QA');
app(CreditNoteService::class)->issue($cn6->fresh());
app(CreditNoteService::class)->applyToInvoice($cn6->fresh(), $i6->fresh());   // credit note
$small6 = $bill($mk(5000), '2026-07');
$adv6 = Payment::create(['tenant_id' => $i6->tenant_id, 'amount' => 30000,
    'payment_date' => '2026-07-01', 'method' => 'bank_transfer', 'status' => 'captured', 'reference' => 'QA-ADV2-'.uniqid()]);
$adv6->invoices()->attach($small6->id, ['allocated_amount' => 5000]);
$small6->fresh()->recomputeTotals();
app(ApplyTenantCreditService::class)->applyToInvoice($i6->fresh(), 25000);  // tenant credit
DepositTransaction::create(['lease_id' => $l6->id, 'tenant_id' => $l6->tenant_id, 'asset_id' => $asset->id,
    'type' => 'receipt', 'amount' => 25000, 'transaction_date' => '2026-01-05', 'status' => 'recorded']);
$l6->update(['status' => 'terminated', 'expiry_date' => '2026-08-31']);
app(ApplyDepositToInvoiceService::class)->settleOpenAr($l6->fresh(), CarbonImmutable::parse('2026-09-01')); // deposit
$i6->refresh();
printf("  cash=%s credit_note=%s tenant_credit=%s deposit=%s\n",
    number_format($i6->capturedCashPaid(), 2), number_format((float) $i6->credit_applied_amount, 2),
    number_format((float) TenantCreditApplication::where('invoice_id', $i6->id)->sum('amount'), 2),
    number_format((float) DepositApplication::where('invoice_id', $i6->id)->sum('amount'), 2));
qa_eq('the four channels settle the invoice exactly', 100000.00, (float) $i6->paid_amount);
qa_eq('balance is zero', 0.00, (float) $i6->balance);
qa_eq('status paid', 'paid', $i6->status);
qa_refuses('a fifth pound cannot be squeezed in', function () use ($i6) {
    $p = Payment::create(['tenant_id' => $i6->tenant_id, 'amount' => 1,
        'payment_date' => '2026-08-20', 'method' => 'cash', 'status' => 'captured', 'reference' => 'QA-'.uniqid()]);
    DB::transaction(function () use ($p, $i6) {
        $p->invoices()->sync([$i6->id => ['allocated_amount' => 1]]);
        $p->assertInvoicesNotOverAllocated([$i6->id]);
    });
});

qa_section('AR 8 — accounting tie-out across all four channels');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after four-channel settlement');
$svc = app(BooksReconciliationService::class);
$tie = $svc->glTieOut();
printf("  AR gl=%s expected=%s delta=%s\n", number_format($tie['ar']['gl'], 2), number_format($tie['ar']['expected'], 2), $tie['ar']['delta']);
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('no GL drift', 0, count($svc->glDriftDiscrepancies()));
qa_eq('deposits tie out', 0, count($svc->depositTieOutDiscrepancies()));

qa_summary();
