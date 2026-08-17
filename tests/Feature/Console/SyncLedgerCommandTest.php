<?php

use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\SystemSetting;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\LedgerReportService;
use App\Services\VendorBillService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    // The command opens fiscal years itself.
});

/** Issued invoice with two consistent items (rent 10,000 + service 1,000 + 140 VAT = 11,140). */
function syncInvoice(): Invoice
{
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 11000,
        'vat_amount' => 140,
        'total' => 11140,
        'balance' => 11140,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);
    $invoice->items()->create(['type' => 'service_charge', 'description' => 'Service', 'amount' => 1000, 'vat_rate' => 14, 'vat_amount' => 140, 'total' => 1140]);

    return $invoice;
}

it('backfills journal entries for invoices, payments, and credit notes', function () {
    $invoice = syncInvoice();
    $payment = Payment::create([
        'reference' => 'PAY-SYNC-1', 'tenant_id' => $invoice->tenant_id, 'amount' => 5000,
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);
    CreditNote::create([
        'tenant_id' => $invoice->tenant_id, 'lease_id' => $invoice->lease_id, 'status' => 'issued',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 500, 'vat_amount' => 70, 'total' => 570, 'applied_amount' => 0, 'balance' => 570, 'currency' => 'EGP',
    ]);

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    expect(JournalEntry::where('status', 'posted')->count())->toBe(3);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('backfills vendor bills and their payments (AP)', function () {
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id,
        'asset_id' => makeAsset()->id,
        'category' => 'utilities',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'subtotal' => 2000, 'vat_amount' => 280, 'total' => 2280, 'balance' => 2280,
    ]);
    app(VendorBillService::class)->recordPayment($bill, 1000, 'bank_transfer');

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    // one entry for the bill + one for its payment
    expect(JournalEntry::where('source_type', $bill->getMorphClass())->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('backfills direct expenses', function () {
    $expense = Expense::create([
        'asset_id' => makeAsset()->id,
        'category' => 'admin',
        'amount' => 500, 'vat_amount' => 0, 'total' => 500,
        'paid_from' => 'cash', 'expense_date' => now()->toDateString(), 'status' => 'recorded',
    ]);

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    expect(JournalEntry::where('source_type', $expense->getMorphClass())->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('voids the entry when an expense is later cancelled', function () {
    $expense = Expense::create([
        'asset_id' => makeAsset()->id, 'category' => 'admin',
        'amount' => 500, 'vat_amount' => 0, 'total' => 500,
        'paid_from' => 'cash', 'expense_date' => now()->toDateString(), 'status' => 'recorded',
    ]);
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $expense->update(['status' => 'cancelled']);
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $entries = JournalEntry::where('source_type', $expense->getMorphClass())->where('source_id', $expense->id)->get();
    expect($entries->where('status', 'void')->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['total_debit'])->toEqualWithDelta(0.0, 0.001);
});

it('backfills approved payroll runs', function () {
    $payroll = Payroll::create([
        'asset_id' => makeAsset()->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 20000, 'salary_tax' => 2000, 'social_insurance' => 1500,
        'paid_from' => 'bank', 'status' => 'approved',
    ]);

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    expect(JournalEntry::where('source_type', $payroll->getMorphClass())->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('backfills security-deposit transactions', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $deposit = DepositTransaction::create([
        'lease_id' => $lease->id, 'type' => 'receipt', 'amount' => 5000,
        'transaction_date' => now()->toDateString(), 'method' => 'bank', 'status' => 'recorded',
    ]);

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    expect(JournalEntry::where('source_type', $deposit->getMorphClass())->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('records when the ledger was last synced', function () {
    expect(SystemSetting::get('ledger_last_synced_at'))->toBeNull();

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    expect(SystemSetting::get('ledger_last_synced_at'))->not->toBeNull();
});

it('is idempotent — a second backfill posts nothing new', function () {
    syncInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();
    $after = JournalEntry::count();

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    expect(JournalEntry::count())->toBe($after);
});

it('voids the entry when an invoice is later cancelled', function () {
    $invoice = syncInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $invoice->update(['status' => 'cancelled']);
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $entries = JournalEntry::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->get();
    // original is voided, plus a reversing entry → the source nets to zero in reports.
    expect($entries->where('status', 'void')->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['total_debit'])->toEqualWithDelta(0.0, 0.001);
});

it('voids the entry when an invoice is later soft-deleted', function () {
    $invoice = syncInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    trashBypassingDeletionPolicy($invoice); // soft-delete a posted document

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    // The sweep visits the trashed doc (withTrashed) and voids its now-effectless entry.
    $entries = JournalEntry::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->get();
    expect($entries->where('status', 'void')->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['total_debit'])->toEqualWithDelta(0.0, 0.001);
});

it('re-derives the entry when an invoice total changes (e.g. a late fee)', function () {
    $invoice = syncInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    // Simulate a late fee: a new item + bumped total.
    $invoice->items()->create(['type' => 'late_fee', 'description' => 'Late fee', 'amount' => 200, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 200]);
    $invoice->update(['subtotal' => 11200, 'total' => 11340, 'balance' => 11340]);

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    // The current (posted, non-void) entry reflects the new total; books still balance.
    $current = JournalEntry::where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->id)->where('status', 'posted')->latest('id')->first();
    expect($current->totalDebit())->toEqualWithDelta(11340.0, 0.001);

    $ar = LedgerAccount::where('code', '11201001')->first();
    $statement = app(LedgerReportService::class)->accountLedger($ar);
    expect($statement['closing'])->toEqualWithDelta(11340.0, 0.001); // AR reflects the fee
});
