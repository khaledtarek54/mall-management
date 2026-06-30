<?php

use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Services\Accounting\LedgerReportService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    // The command opens fiscal years itself.
});

/** Issued invoice with two consistent items (rent 10,000 + service 1,000 + 140 VAT = 11,140). */
function syncInvoice(): \App\Models\Invoice
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
