<?php

use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
});

/** A lease whose unit belongs to a fresh asset. */
function glLease(): \App\Models\Lease
{
    return makeLease(makeUnit(makeAsset()));
}

/** An issued invoice with two consistent items (rent 10,000 ex-VAT + service 1,000 + 140 VAT). */
function glInvoice(\App\Models\Lease $lease): \App\Models\Invoice
{
    $invoice = makeInvoice($lease, [
        'issue_date' => now()->toDateString(),
        'subtotal' => 11000,
        'vat_amount' => 140,
        'total' => 11140,
        'balance' => 11140,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Base rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);
    $invoice->items()->create(['type' => 'service_charge', 'description' => 'Service charge', 'amount' => 1000, 'vat_rate' => 14, 'vat_amount' => 140, 'total' => 1140]);

    return $invoice;
}

it('journalizes an invoice as Dr AR / Cr revenue + VAT', function () {
    $invoice = glInvoice(glLease());

    $entry = $this->poster->post($invoice);

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    expect($entry->totalDebit())->toEqualWithDelta(11140.0, 0.001);

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('accounts_receivable')]->debit)->toEqualWithDelta(11140.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('rent_revenue')]->credit)->toEqualWithDelta(10000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('service_charge_revenue')]->credit)->toEqualWithDelta(1000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('vat_payable')]->credit)->toEqualWithDelta(140.0, 0.001);
});

it('routes a CAM-recovery item to the dedicated CAM Recovery Revenue account', function () {
    $invoice = makeInvoice(glLease(), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 3000, 'vat_amount' => 0, 'total' => 3000, 'balance' => 3000,
    ]);
    $invoice->items()->create(['type' => 'cam_recovery', 'description' => 'CAM Reconciliation — 2025', 'amount' => 3000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 3000]);

    $entry = $this->poster->post($invoice);

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('cam_recovery_revenue')]->credit)->toEqualWithDelta(3000.0, 0.001);
    // …and NOT lumped into generic misc income.
    expect($byAccount->has($this->accounts->id('misc_income')))->toBeFalse();
});

it('falls back to the invoice header when there are no line items', function () {
    // Header-only invoice (no items) — must still post a balanced entry.
    $invoice = makeInvoice(glLease(), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 36052.50,
        'vat_amount' => 0,
        'total' => 36052.50,
        'balance' => 36052.50,
    ]);

    $entry = $this->poster->post($invoice);

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('accounts_receivable')]->debit)->toEqualWithDelta(36052.50, 0.001);
    expect((float) $byAccount[$this->accounts->id('misc_income')]->credit)->toEqualWithDelta(36052.50, 0.001);
});

it('journalizes a captured payment as Dr Bank / Cr AR', function () {
    $invoice = glInvoice(glLease());
    $payment = Payment::create([
        'reference' => 'PAY-GL-1',
        'tenant_id' => $invoice->tenant_id,
        'amount' => 11140,
        'method' => 'bank_transfer',
        'status' => 'captured',
        'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 11140]);

    $entry = $this->poster->post($payment->refresh());

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('bank')]->debit)->toEqualWithDelta(11140.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('accounts_receivable')]->credit)->toEqualWithDelta(11140.0, 0.001);
});

it('routes cash payments to the cash account', function () {
    $invoice = glInvoice(glLease());
    $payment = Payment::create([
        'reference' => 'PAY-GL-2',
        'tenant_id' => $invoice->tenant_id,
        'amount' => 500,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 500]);

    $entry = $this->poster->post($payment->refresh());

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect($byAccount->has($this->accounts->id('cash')))->toBeTrue();
});

it('skips an uncaptured payment (no GL effect)', function () {
    $invoice = glInvoice(glLease());
    $payment = Payment::create([
        'reference' => 'PAY-GL-3',
        'tenant_id' => $invoice->tenant_id,
        'amount' => 500,
        'method' => 'cash',
        'status' => 'initiated',
        'payment_date' => now()->toDateString(),
    ]);

    expect($this->poster->post($payment))->toBeNull();
});

it('journalizes a credit note as Dr sales-returns + VAT / Cr AR', function () {
    $lease = glLease();
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id,
        'lease_id' => $lease->id,
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'reason' => 'adjustment',
        'subtotal' => 500,
        'vat_amount' => 70,
        'total' => 570,
        'applied_amount' => 0,
        'balance' => 570,
        'currency' => 'EGP',
    ]);

    $entry = $this->poster->post($note);

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('sales_returns')]->debit)->toEqualWithDelta(500.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('vat_payable')]->debit)->toEqualWithDelta(70.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('accounts_receivable')]->credit)->toEqualWithDelta(570.0, 0.001);
});

it('is idempotent — re-posting the same document does not double-book', function () {
    $invoice = glInvoice(glLease());

    $first = $this->poster->post($invoice);
    $second = $this->poster->post($invoice);

    expect($second->id)->toBe($first->id);
    expect(JournalEntry::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->count())->toBe(1);
});

it('ties the GL receivables balance to the invoice balance after payment', function () {
    $invoice = glInvoice(glLease());
    $this->poster->post($invoice); // AR +11140

    $payment = Payment::create([
        'reference' => 'PAY-GL-4',
        'tenant_id' => $invoice->tenant_id,
        'amount' => 11140,
        'method' => 'bank_transfer',
        'status' => 'captured',
        'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 11140]);
    $invoice->recomputeTotals(); // mirrors the live payment-capture recompute
    $this->poster->post($payment->refresh()); // AR -11140

    $ar = LedgerAccount::where('code', '11201001')->first();
    $statement = app(LedgerReportService::class)->accountLedger($ar);

    // GL receivables closing should equal the invoice's own outstanding balance.
    expect($statement['closing'])->toEqualWithDelta((float) $invoice->fresh()->balance, 0.001);
});

it('skips a draft invoice — revenue is recognized only at issue', function () {
    $invoice = glInvoice(glLease());
    // Force a persisted draft (the model's auto-status hook would otherwise flip it).
    \Illuminate\Support\Facades\DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'draft']);

    expect($this->poster->post($invoice->fresh()))->toBeNull();
});

it('skips a vendor-bill payment whose bill is not postable (draft/cancelled)', function () {
    $bill = \App\Models\VendorBill::create([
        'vendor_id' => \App\Models\Vendor::factory()->create()->id, 'asset_id' => makeAsset()->id,
        'category' => 'utilities', 'status' => 'draft', 'bill_date' => now()->toDateString(),
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000,
    ]);
    $payment = \App\Models\VendorBillPayment::create([
        'vendor_bill_id' => $bill->id, 'amount' => 500, 'method' => 'bank_transfer', 'payment_date' => now()->toDateString(),
    ]);

    expect($this->poster->post($payment->fresh()))->toBeNull();
});

it('books a payment overpayment to unearned revenue', function () {
    $invoice = glInvoice(glLease()); // total 11140
    $payment = Payment::create([
        'reference' => 'PAY-OVER', 'tenant_id' => $invoice->tenant_id, 'amount' => 12000,
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 11140]);

    $entry = $this->poster->post($payment->refresh());
    $byAccount = $entry->lines->keyBy('ledger_account_id');

    expect($entry->isBalanced())->toBeTrue();
    expect((float) $byAccount[$this->accounts->id('bank')]->debit)->toEqualWithDelta(12000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('accounts_receivable')]->credit)->toEqualWithDelta(11140.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('unearned_revenue')]->credit)->toEqualWithDelta(860.0, 0.001);
});

it('omits the sales-returns line on a pure-VAT credit note', function () {
    $lease = glLease();
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'issued',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 0, 'vat_amount' => 70, 'total' => 70, 'applied_amount' => 0, 'balance' => 70, 'currency' => 'EGP',
    ]);

    $entry = $this->poster->post($note);
    $byAccount = $entry->lines->keyBy('ledger_account_id');

    expect($entry->isBalanced())->toBeTrue();
    expect($byAccount->has($this->accounts->id('sales_returns')))->toBeFalse(); // net return 0 → no line
    expect((float) $byAccount[$this->accounts->id('vat_payable')]->debit)->toEqualWithDelta(70.0, 0.001);
});
