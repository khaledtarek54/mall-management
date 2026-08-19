<?php

require __DIR__.'/boot.php';
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\Expense;
use App\Models\FacilityWorkOrder;
use App\Models\JournalEntry;
use App\Models\SlaPenalty;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Accounting\AccountResolver;
use App\Services\ApplySlaPenaltyService;
use App\Services\ExpenseService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\VendorBillService;
use App\Services\VoidVendorBillPaymentService;
use App\Settings\TaxSettings;
use App\Support\WithholdingTax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$vendor = Vendor::first();
$svc = app(VendorBillService::class);
$mkBill = function (float $net, float $vat, string $cat = 'maintenance', string $date = '2026-08-01') use ($asset, $vendor): VendorBill {
    return VendorBill::create(['vendor_id' => $vendor->id, 'asset_id' => $asset->id,
        'number' => 'QA-VB-'.strtoupper(bin2hex(random_bytes(3))), 'bill_date' => $date, 'due_date' => '2026-09-01',
        'category' => $cat, 'subtotal' => $net, 'vat_amount' => $vat, 'total' => round($net + $vat, 2),
        'status' => 'draft', 'currency' => 'EGP', 'description' => 'QA bill']);
};

qa_section('AP 1 — a draft bill is not a payable');
$b1 = $mkBill(100000, 14000);
qa_eq('total = net + VAT', 114000.00, (float) $b1->total);
qa_eq('status draft', 'draft', $b1->status);
qa_ok('a draft posts nothing to the GL', ! $b1->isPostable());
qa_eq('…so no journal entry exists', 0, JournalEntry::where('source_type', $b1->getMorphClass())->where('source_id', $b1->id)->where('status', 'posted')->count());
qa_eq('a draft cannot be paid', 0.0, $svc->recordPayment($b1->fresh(), 10000));

qa_section('AP 2 — approval recognises the payable, Dr expense + Dr VAT recoverable / Cr AP');
$svc->approve($b1->fresh());
$b1->refresh();
qa_eq('status approved', 'approved', $b1->status);
qa_eq('balance = the full total', 114000.00, (float) $b1->balance);
$e = qa_sync($b1->fresh());
qa_dump_entry($e, 'vendor bill');
qa_ok('an entry was posted', $e !== null);
$dr = (float) $e->lines->sum('debit');
$cr = (float) $e->lines->sum('credit');
qa_eq('the entry balances', $dr, $cr);
qa_eq('…at the bill total', 114000.00, $cr);
qa_eq('entry is dated by bill_date', '2026-08-01', $e->entry_date->format('Y-m-d'));
qa_eq('the expense line is net of VAT', 100000.00,
    (float) $e->lines->firstWhere('ledger_account_id', app(AccountResolver::class)->id('maintenance_expense'))?->debit);
qa_eq('VAT is recoverable, not expensed', 14000.00,
    (float) $e->lines->firstWhere('ledger_account_id', app(AccountResolver::class)->id('vat_recoverable'))?->debit);
qa_eq('AP is credited the gross', 114000.00,
    (float) $e->lines->firstWhere('ledger_account_id', app(AccountResolver::class)->id('accounts_payable'))?->credit);

qa_section('AP 3 — payment cannot exceed the balance');
$paid = $svc->recordPayment($b1->fresh(), 50000, 'bank_transfer', CarbonImmutable::parse('2026-08-10'));
qa_eq('part payment applied', 50000.00, $paid);
qa_eq('balance falls', 64000.00, (float) $b1->fresh()->balance);
$over = $svc->recordPayment($b1->fresh(), 999999, 'bank_transfer', CarbonImmutable::parse('2026-08-11'));
qa_eq('an over-payment is CAPPED at the balance, never over-paid', 64000.00, $over);
qa_eq('balance zero', 0.00, (float) $b1->fresh()->balance);
qa_eq('status paid', 'paid', $b1->fresh()->status);
qa_eq('a further payment applies nothing', 0.0, $svc->recordPayment($b1->fresh(), 1000));

qa_section('AP 4 — withholding tax is computed on the NET, never on VAT');
$taxSettings = app(TaxSettings::class);
printf("  WHT ships OFF by default: %s\n", var_export(WithholdingTax::enabled(), true));
qa_ok('withholding ships OFF until the accountant confirms the rates', ! WithholdingTax::enabled());
$taxSettings->fill(['wht_enabled' => true])->save();
app()->forgetInstance(TaxSettings::class);
qa_eq('switching WHT on ALONE still withholds nothing (no default code is set)',
    0.0, WithholdingTax::rateFor($vendor));
$taxSettings->fill(['wht_default_tax_code' => 'WH_3'])->save();
app()->forgetInstance(TaxSettings::class);
printf("  WHT enabled=%s · default code WH_3 · rate for this vendor=%s%%\n",
    var_export(WithholdingTax::enabled(), true), number_format(WithholdingTax::rateFor($vendor), 2));
qa_eq('with a default code the rate resolves (catalogue stores it negative, used positive)',
    3.0, WithholdingTax::rateFor($vendor));
$b2 = $mkBill(100000, 14000, 'maintenance');
$svc->approve($b2->fresh());
$p2 = $svc->recordPayment($b2->fresh(), 114000, 'bank_transfer', CarbonImmutable::parse('2026-08-12'));
$vbp = VendorBillPayment::where('vendor_bill_id', $b2->id)->latest('id')->first();
printf("  paid gross=%s withheld=%s\n", number_format($p2, 2), number_format((float) $vbp->withholding_amount, 2));
$rate = WithholdingTax::rateFor($vendor);
if ($rate > 0) {
    qa_eq('withheld = rate x the NET (100,000), not the gross (114,000)',
        round(100000 * $rate / 100, 2), (float) $vbp->withholding_amount);
    qa_ok('…so it is NOT rate x gross (the over-withholding bug)',
        abs((float) $vbp->withholding_amount - round(114000 * $rate / 100, 2)) > 0.01,
        'rate x gross would be '.number_format(114000 * $rate / 100, 2));
} else {
    echo "  (no withholding rate resolves for this vendor — catalogue ships stamp/schedule inactive)\n";
    qa_eq('no rate resolves, so nothing is withheld rather than a guess', 0.00, (float) $vbp->withholding_amount);
}
qa_eq('the bill is settled in FULL regardless of withholding', 0.00, (float) $b2->fresh()->balance);
$e2 = qa_sync($vbp->fresh());
qa_dump_entry($e2, 'vendor bill payment');
if ($e2) {
    qa_eq('the payment entry balances', (float) $e2->lines->sum('debit'), (float) $e2->lines->sum('credit'));
}

qa_section('AP 5 — voiding a bill payment re-opens the payable');
$b3 = $mkBill(50000, 7000);
$svc->approve($b3->fresh());
$svc->recordPayment($b3->fresh(), 57000, 'bank_transfer', CarbonImmutable::parse('2026-08-13'));
$vbp3 = VendorBillPayment::where('vendor_bill_id', $b3->id)->latest('id')->first();
qa_eq('bill paid', 0.00, (float) $b3->fresh()->balance);
app(VoidVendorBillPaymentService::class)->void($vbp3->fresh(), 'QA reversal');
qa_eq('voiding restores the payable', 57000.00, (float) $b3->fresh()->balance);
qa_ok('…and the bill is no longer paid', $b3->fresh()->status !== 'paid', $b3->fresh()->status);

qa_section('AP 6 — cancel refuses once money has moved');
$b4 = $mkBill(20000, 2800);
$svc->approve($b4->fresh());
qa_allows('an unpaid approved bill can be cancelled', fn () => $svc->cancel($b4->fresh()));
qa_eq('…and claims no payable', 0.00, (float) $b4->fresh()->balance);
$b5 = $mkBill(20000, 2800);
$svc->approve($b5->fresh());
$svc->recordPayment($b5->fresh(), 5000, 'cash', CarbonImmutable::parse('2026-08-14'));
qa_refuses('a part-paid bill cannot be cancelled', fn () => $svc->cancel($b5->fresh()), null, Throwable::class);

qa_section('AP 7 — a bill dated into a CLOSED period cannot be approved');
$period = AccountingPeriod::where('status', 'open')->orderBy('starts_on')->first();
if ($period) {
    $period->forceFill(['status' => 'closed'])->save();
    $closed = CarbonImmutable::parse($period->starts_on)->addDays(2)->toDateString();
    $b6 = $mkBill(10000, 1400, 'maintenance', $closed);
    qa_refuses('approval is refused rather than silently failing to post',
        fn () => $svc->approve($b6->fresh()));
    qa_eq('…and the bill stays a draft the operator can re-date', 'draft', $b6->fresh()->status);
    $b7 = $mkBill(10000, 1400);
    $svc->approve($b7->fresh());
    qa_refuses('a bill PAYMENT dated into a closed period is refused',
        fn () => $svc->recordPayment($b7->fresh(), 1000, 'cash', CarbonImmutable::parse($closed)));
    $period->forceFill(['status' => 'open'])->save();
}

qa_section('AP 8 — SLA penalties deduct from the vendor bill AND post to the GL');
$wo = FacilityWorkOrder::where('asset_id', $asset->id)->first();
$pen = SlaPenalty::create(['facility_work_order_id' => $wo?->id, 'asset_id' => $asset->id, 'vendor_id' => $vendor->id,
    'basis' => 'flat', 'rate' => 0, 'hours_over_sla' => 10, 'amount' => 7500, 'currency' => 'EGP',
    'status' => SlaPenalty::STATUS_FINAL, 'finalised_at' => now()]);
if ($pen) {
    $taxSettings->fill(['wht_enabled' => false, 'wht_default_tax_code' => ''])->save();
    app()->forgetInstance(TaxSettings::class);
    $b8 = $mkBill(100000, 0, 'maintenance');
    $b8->update(['vendor_id' => $pen->vendor_id ?? $vendor->id]);
    $svc->approve($b8->fresh());
    $before = (float) $b8->fresh()->balance;
    try {
        app(ApplySlaPenaltyService::class)->toBill($pen->fresh(), $b8->fresh());
        $after = (float) $b8->fresh()->balance;
        printf("  penalty %s: balance %s → %s\n", number_format((float) $pen->amount, 2), number_format($before, 2), number_format($after, 2));
        qa_eq('the bill balance drops by the penalty', round($before - (float) $pen->amount, 2), $after, 0.02);
        $pe = qa_sync($pen->fresh());
        qa_ok('the penalty posts its OWN GL entry (the registry-gap bug)', $pe !== null, $pe?->id);
        if ($pe) {
            qa_eq('…and it balances', (float) $pe->lines->sum('debit'), (float) $pe->lines->sum('credit'));
        }
        app(ApplySlaPenaltyService::class)->detach($pen->fresh());
        qa_eq('detaching restores the bill balance', $before, (float) $b8->fresh()->balance, 0.02);
    } catch (Throwable $t) {
        qa_ok('SLA penalty applied to a bill', false, get_class($t).': '.$t->getMessage());
    }
} else {
    echo "  (no SLA penalty available in the dataset)\n";
}

qa_section('AP 9 — expenses');
$exp = Expense::create(['asset_id' => $asset->id, 'vendor_id' => $vendor->id, 'category' => 'maintenance',
    'description' => 'QA petty expense', 'amount' => 5000, 'vat_amount' => 700, 'total' => 5700,
    'expense_date' => '2026-08-05', 'status' => 'recorded', 'payment_method' => 'cash', 'currency' => 'EGP']);
$ee = qa_sync($exp->fresh());
qa_dump_entry($ee, 'expense');
qa_ok('an approved expense posts', $ee !== null);
if ($ee) {
    qa_eq('…and balances', (float) $ee->lines->sum('debit'), (float) $ee->lines->sum('credit'));
}
app(ExpenseService::class)->cancel($exp->fresh());
qa_eq('cancelling marks it cancelled', 'cancelled', $exp->fresh()->status);
$ee2 = qa_sync($exp->fresh());
qa_ok('…and its entry is reversed/voided', $ee2 === null || $ee2->status !== 'posted',
    'entry status = '.($ee2?->status ?? 'none'));

qa_section('AP 10 — accounting tie-out');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after payables');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
printf("  AP gl=%s expected=%s delta=%s\n", number_format($tie['ap']['gl'], 2), number_format($tie['ap']['expected'], 2), $tie['ap']['delta']);
qa_eq('AP ties to the vendor-bill balances', 0.0, $tie['ap']['delta']);
qa_eq('AR still ties', 0.0, $tie['ar']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
