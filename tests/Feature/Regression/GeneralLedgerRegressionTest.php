<?php

use App\Models\CreditNote;
use App\Models\Payment;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
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
    $this->reports = app(LedgerReportService::class);
});

// Phase 0 review fix: a voided entry's original was hidden from reports while its
// reversal still counted — corrupting the trial balance. Both must net to zero.
it('void leaves the trial balance at zero (original + reversal both counted)', function () {
    $je = app(JournalPostingService::class)->post([
        'lines' => [
            ['ledger_account_id' => $this->accounts->id('bank'), 'debit' => 1000, 'credit' => 0],
            ['ledger_account_id' => $this->accounts->id('rent_revenue'), 'debit' => 0, 'credit' => 1000],
        ],
    ]);

    app(JournalPostingService::class)->void($je);

    expect($this->reports->trialBalance()['total_debit'])->toEqualWithDelta(0.0, 0.001);
});

// Phase 1 fix: an invoice with no line items must still post a balanced entry
// (header fallback → misc_income) instead of throwing "needs at least two lines".
it('posts a balanced entry for an item-less (header-only) invoice', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(), 'subtotal' => 5000, 'vat_amount' => 0,
        'total' => 5000, 'balance' => 5000,
    ]);

    $entry = $this->poster->post($invoice);

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('misc_income')]->credit)->toEqualWithDelta(5000.0, 0.001);
});

// Phase 1 review fix: a payment paying invoices across DIFFERENT properties must
// credit each property's receivables on its OWN asset, not lump them under the first.
it('splits a cross-property payment to each asset receivable', function () {
    $tenant = makeTenant();
    $leaseA = makeLease(makeUnit($assetA = makeAsset()), $tenant);
    $leaseB = makeLease(makeUnit($assetB = makeAsset()), $tenant);
    $invA = makeInvoice($leaseA, ['tenant_id' => $tenant->id, 'total' => 1000, 'balance' => 1000]);
    $invB = makeInvoice($leaseB, ['tenant_id' => $tenant->id, 'total' => 2000, 'balance' => 2000]);

    $payment = Payment::create([
        'reference' => 'PAY-XA-1', 'tenant_id' => $tenant->id, 'amount' => 3000,
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invA->id, ['allocated_amount' => 1000]);
    $payment->invoices()->attach($invB->id, ['allocated_amount' => 2000]);

    $entry = $this->poster->post($payment->refresh());

    expect($entry->isBalanced())->toBeTrue();
    $arId = $this->accounts->id('accounts_receivable');
    $arLines = $entry->lines->where('ledger_account_id', $arId)->keyBy('asset_id');
    expect((float) $arLines[$assetA->id]->credit)->toEqualWithDelta(1000.0, 0.001);
    expect((float) $arLines[$assetB->id]->credit)->toEqualWithDelta(2000.0, 0.001);
    expect($entry->asset_id)->toBeNull(); // genuinely cross-property → consolidated
});

// Phase 2 review fix: the balance sheet's net income must equal the income
// statement's net profit for the same scope — both round per account then sum, so
// they can't penny-drift apart.
it('balance-sheet net income equals income-statement net profit', function () {
    $post = app(JournalPostingService::class);
    $r = $this->accounts;
    foreach ([['accounts_receivable', 'rent_revenue', 333.33], ['accounts_receivable', 'service_charge_revenue', 666.67], ['salaries_expense', 'bank', 100.01]] as [$d, $c, $amt]) {
        $post->post(['lines' => [
            ['ledger_account_id' => $r->id($d), 'debit' => $amt, 'credit' => 0],
            ['ledger_account_id' => $r->id($c), 'debit' => 0, 'credit' => $amt],
        ]]);
    }

    $year = (int) now()->year;
    $netProfit = $this->reports->incomeStatement(null, \Carbon\CarbonImmutable::create($year, 1, 1), \Carbon\CarbonImmutable::create($year, 12, 31))['net_profit'];
    $netIncome = $this->reports->balanceSheet(null, \Carbon\CarbonImmutable::create($year, 12, 31))['net_income'];

    expect($netIncome)->toBe($netProfit);
});

// Phase 3 review fix: cancelling a bill zeroes its balance via recompute(), and a
// later recompute() must NOT resurrect the payable (balance stays 0).
it('keeps a cancelled vendor bill at zero balance across recompute', function () {
    $bill = \App\Models\VendorBill::create([
        'vendor_id' => \App\Models\Vendor::factory()->create()->id,
        'asset_id' => makeAsset()->id,
        'category' => 'admin',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
    ]);

    app(\App\Services\VendorBillService::class)->cancel($bill);
    expect((float) $bill->fresh()->balance)->toEqualWithDelta(0.0, 0.001);

    $bill->fresh()->recompute(); // must not restore balance = total
    expect((float) $bill->fresh()->balance)->toEqualWithDelta(0.0, 0.001);
    expect($bill->fresh()->status)->toBe('cancelled');
});

// Expense-phase review fix: a pure-VAT document (net 0) must NOT emit a
// debit-0/credit-0 expense line (which the posting engine rejects, silently
// dropping the document). The net line is guarded; only VAT + cash/AP post.
it('posts a balanced pure-VAT expense with no zero-amount line', function () {
    $expense = \App\Models\Expense::create([
        'asset_id' => makeAsset()->id,
        'category' => 'utilities',
        'amount' => 0, 'vat_amount' => 140, // net 0
        'paid_from' => 'cash', 'expense_date' => now()->toDateString(), 'status' => 'recorded',
    ]);

    $entry = $this->poster->post($expense->fresh());

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    expect($entry->lines)->toHaveCount(2); // VAT recoverable + cash, no zero expense line
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('vat_recoverable')]->debit)->toEqualWithDelta(140.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('cash')]->credit)->toEqualWithDelta(140.0, 0.001);
});

it('posts a balanced pure-VAT vendor bill with no zero-amount line', function () {
    $bill = \App\Models\VendorBill::create([
        'vendor_id' => \App\Models\Vendor::factory()->create()->id,
        'asset_id' => makeAsset()->id,
        'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'amount' => 0, 'vat_amount' => 140, // net 0 (subtotal ignored; total enforced = 140)
    ]);

    $entry = $this->poster->post($bill->fresh());

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    expect($entry->lines)->toHaveCount(2); // VAT recoverable + payables, no zero expense line
});

// Phase 3 review fix: total = subtotal + VAT is enforced by the MODEL on every
// write path (not just the form). A programmatic create with NO total must derive
// it (not persist 0, which the journalizer would silently skip), and post balanced.
it('enforces total = subtotal + vat on write and posts a balanced bill', function () {
    $bill = \App\Models\VendorBill::create([
        'vendor_id' => \App\Models\Vendor::factory()->create()->id,
        'asset_id' => makeAsset()->id,
        'category' => 'maintenance',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'subtotal' => 1000, 'vat_amount' => 140, // NO total → must be derived
    ]);

    expect((float) $bill->fresh()->total)->toEqualWithDelta(1140.0, 0.001);

    $entry = $this->poster->post($bill->fresh());

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('maintenance_expense')]->debit)->toEqualWithDelta(1000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('vat_recoverable')]->debit)->toEqualWithDelta(140.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('accounts_payable')]->credit)->toEqualWithDelta(1140.0, 0.001);
});

// Phase 3 review fix: the blank-money coercion must read the RAW attribute — a
// decimal:2 cast throws MathException when '' is read through the getter, which
// would crash the import/API path the coercion exists to protect.
it('coerces blank money strings on a vendor bill without crashing', function () {
    $bill = \App\Models\VendorBill::create([
        'vendor_id' => \App\Models\Vendor::factory()->create()->id,
        'asset_id' => makeAsset()->id,
        'category' => 'other',
        'status' => 'draft',
        'bill_date' => now()->toDateString(),
        'subtotal' => '', 'vat_amount' => '', // blank strings, e.g. from a CSV import
    ]);

    expect((float) $bill->fresh()->subtotal)->toEqualWithDelta(0.0, 0.001);
    expect((float) $bill->fresh()->total)->toEqualWithDelta(0.0, 0.001);
});

// Phase 1 review fix: a credit note whose stored subtotal drifts from total − vat
// must still post a balanced entry (sales_returns is derived from total − vat).
it('derives a balanced credit-note entry when subtotal drifts from total minus vat', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'issued',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 1000, 'vat_amount' => 140, 'total' => 1200, // intentionally inconsistent
        'applied_amount' => 0, 'balance' => 1200, 'currency' => 'EGP',
    ]);

    $entry = $this->poster->post($note);

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('sales_returns')]->debit)->toEqualWithDelta(1060.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('vat_payable')]->debit)->toEqualWithDelta(140.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('accounts_receivable')]->credit)->toEqualWithDelta(1200.0, 0.001);
});
