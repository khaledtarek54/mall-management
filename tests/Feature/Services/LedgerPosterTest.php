<?php

use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MarketingBudget;
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

/** A marketing spend against a fresh property's marketing budget. */
function glMarketingSpend(array $attrs = []): \App\Models\MarketingSpend
{
    $budget = MarketingBudget::create([
        'asset_id' => makeAsset()->id,
        'period_year' => (int) now()->year,
        'accrued_amount' => 5000,
        'spent_amount' => 0,
        'status' => 'open',
    ]);

    return $budget->spends()->create(array_merge([
        'category' => 'promotion',
        'description' => 'Ramadan promo',
        'amount' => 1200,
        'paid_from' => 'cash',
        'spent_on' => now()->toDateString(),
    ], $attrs));
}

it('journalizes a marketing spend as Dr marketing expense / Cr cash', function () {
    $spend = glMarketingSpend(['amount' => 1200, 'paid_from' => 'cash']);

    $entry = $this->poster->post($spend->fresh());

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('marketing_expense')]->debit)->toEqualWithDelta(1200.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('cash')]->credit)->toEqualWithDelta(1200.0, 0.001);
});

it('credits the bank when a marketing spend is paid from bank', function () {
    $spend = glMarketingSpend(['paid_from' => 'bank']);

    $entry = $this->poster->post($spend->fresh());

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect($byAccount->has($this->accounts->id('bank')))->toBeTrue();
    expect($byAccount->has($this->accounts->id('cash')))->toBeFalse();
});

it('skips a zero-amount marketing spend (no GL effect)', function () {
    $spend = glMarketingSpend(['amount' => 0]);

    expect($this->poster->post($spend->fresh()))->toBeNull();
});

it('keeps a marketing spend on the books when its budget is soft-deleted', function () {
    $spend = glMarketingSpend(['amount' => 800]);
    expect($this->poster->post($spend->fresh()))->not->toBeNull();

    // Archiving the budget must NOT void a real spend's expense — only the spend's
    // own deletion does. The journalizer resolves the property withTrashed.
    $spend->budget->delete(); // soft-delete the budget (spend stays alive)

    $entry = $this->poster->sync($spend->fresh());

    expect($entry)->not->toBeNull();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('marketing_expense')]->debit)->toEqualWithDelta(800.0, 0.001);
});

it('voids a marketing spend entry when the spend is soft-deleted', function () {
    $spend = glMarketingSpend(['amount' => 1000]);
    expect($this->poster->sync($spend->fresh()))->not->toBeNull();

    $spend->delete(); // soft-delete → no ledger effect

    expect($this->poster->sync($spend->fresh()))->toBeNull();

    // The original posting is offset by its reversal → marketing expense nets to zero.
    $marketingExpense = LedgerAccount::where('code', '51105001')->first();
    $statement = app(LedgerReportService::class)->accountLedger($marketingExpense);
    expect($statement['closing'])->toEqualWithDelta(0.0, 0.001);
});

/** A warehouse + catalog item for inventory GL tests. */
function glInventory(): array
{
    $asset = makeAsset();
    $warehouse = \App\Models\Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = \App\Models\InventoryItem::create(['sku' => 'SKU-' . uniqid(), 'name' => 'Seal', 'unit' => 'each', 'unit_cost' => 25]);

    return [$asset, $warehouse, $item];
}

it('journalizes a stock receipt as Dr Inventory / Cr GRNI (not the AP control)', function () {
    [$asset, $w, $i] = glInventory();
    $movement = app(\App\Services\StockMovementService::class)->receive($w, $i, 10, 25); // value 250

    $entry = $this->poster->post($movement->fresh());

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('inventory')]->debit)->toEqualWithDelta(250.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('inventory_grni')]->credit)->toEqualWithDelta(250.0, 0.001);
    // Must NOT touch the AP control account (that would break the GL↔AP tie-out).
    expect($byAccount->has($this->accounts->id('accounts_payable')))->toBeFalse();
    expect((int) $entry->asset_id)->toBe($asset->id); // dimensioned to the warehouse's property
});

it('journalizes stock consumption as Dr Maintenance Expense / Cr Inventory', function () {
    [, $w, $i] = glInventory();
    app(\App\Services\StockMovementService::class)->receive($w, $i, 10, 25); // stock to consume from
    $movement = app(\App\Services\StockMovementService::class)->record([
        'warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'consumption', 'quantity' => 4, 'unit_cost' => 25,
    ]); // value 100

    $entry = $this->poster->post($movement->fresh());

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('maintenance_expense')]->debit)->toEqualWithDelta(100.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('inventory')]->credit)->toEqualWithDelta(100.0, 0.001);
});

it('journalizes a shrinkage adjustment as Dr Inventory Adjustment / Cr Inventory', function () {
    [, $w, $i] = glInventory();
    // Stock it first — you cannot write off what was never received. The overdraw floor now
    // keys on the sign, so a negative adjustment is checked against on-hand too (F-84).
    app(\App\Services\StockMovementService::class)->receive($w, $i, 10, 25);
    $movement = app(\App\Services\StockMovementService::class)->adjust($w, $i, -2, ['unit_cost' => 25]); // value 50

    $entry = $this->poster->post($movement->fresh());

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('inventory_adjustment')]->debit)->toEqualWithDelta(50.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('inventory')]->credit)->toEqualWithDelta(50.0, 0.001);
});

it('does not post a stock transfer to the GL (intra-company move)', function () {
    [, $w, $i] = glInventory();
    // `transfer_out` is in REMOVES_STOCK, so the sign-keyed floor applies to it as well —
    // you cannot transfer out stock the warehouse doesn't hold. (Transfers are still unbuilt:
    // nothing in the app creates one. When they are built they need a paired atomic out/in,
    // and this floor is already the right guard for the out leg.)
    app(\App\Services\StockMovementService::class)->receive($w, $i, 10, 25);
    $movement = app(\App\Services\StockMovementService::class)->record([
        'warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'transfer_out', 'quantity' => 3, 'unit_cost' => 25,
    ]);

    expect($this->poster->post($movement->fresh()))->toBeNull();
});

it('voids a stock movement entry when the movement is soft-deleted', function () {
    [, $w, $i] = glInventory();
    $movement = app(\App\Services\StockMovementService::class)->receive($w, $i, 10, 25);
    expect($this->poster->sync($movement->fresh()))->not->toBeNull();

    $movement->delete();

    expect($this->poster->sync($movement->fresh()))->toBeNull();
    $inventory = LedgerAccount::where('code', '11301001')->first();
    $statement = app(LedgerReportService::class)->accountLedger($inventory);
    expect($statement['closing'])->toEqualWithDelta(0.0, 0.001);
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
