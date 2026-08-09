<?php

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Models\JournalLine;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\Reports\ReportService;
use App\Services\WriteOffInvoiceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Bad-debt write-off (MF-04) — the accounting instrument Atriom did not have.
 *
 * An uncollectible receivable had two homes before this and both were wrong. **Cancel** reverses
 * the revenue in the CURRENT period, including revenue earned and recognised in a prior year, so
 * the year it was actually earned is understated, this year is overstated, and the bad debt never
 * appears as bad debt. **Leave it** and AR aging carries fiction forever.
 *
 * The tests below therefore assert the thing that distinguishes a write-off from a cancellation:
 * **the revenue stays where it was earned** while the loss lands where it was recognised. And per
 * the GL-registry invariant, the tie-out is proved by driving the REAL service and the REAL
 * `accounting:sync-ledger` sweep — a test that calls `LedgerPoster::post()` directly would prove
 * only the journalizer's arithmetic.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->asset = makeAsset();
});

function writableInvoice(float $total = 20000, array $attrs = []): Invoice
{
    $lease = makeLease(makeUnit(test()->asset));

    return Invoice::create(array_merge([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'overdue',
        'issue_date' => '2026-01-01',
        'due_date' => '2026-01-08',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'subtotal' => $total, 'vat_amount' => 0, 'total' => $total,
        'paid_amount' => 0, 'balance' => $total,
    ], $attrs));
}

/* ---- the accounting, which is the whole point ------------------------------ */

it('books the loss to bad debt and relieves AR, without touching the revenue', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $invoice = writableInvoice(20000);

    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    // Drive the REAL sweep, not the poster — the GL-registry invariant.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $accounts = app(AccountResolver::class);
    $badDebt = $accounts->account('bad_debt_expense');
    $ar = $accounts->account('accounts_receivable');

    $writeOff = InvoiceWriteOff::sole();

    $debit = JournalLine::where('ledger_account_id', $badDebt->id)->sum('debit');
    $credit = JournalLine::where('ledger_account_id', $ar->id)->sum('credit');

    expect((float) $debit)->toBe(20000.0)
        ->and((float) $credit)->toBe(20000.0)
        // Dated at the DECISION, not the invoice: the loss belongs to the period it was recognised.
        ->and($writeOff->entry_date->toDateString())->toBe('2026-06-15');
});

it('retires the invoice as written_off, NOT cancelled', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $invoice = writableInvoice(20000);

    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_absconded']);

    // The distinction is the feature: 'cancelled' says this should never have been billed and
    // reverses the revenue; 'written_off' says it was rightly billed and will not be paid.
    expect($invoice->fresh()->status)->toBe('written_off')
        // The balance is left standing as the record of WHAT was written off. It is derived from
        // payments and credit by recomputeTotals(), and a write-off is neither.
        ->and((float) $invoice->fresh()->balance)->toBe(20000.0);
});

it('keeps the books tying out after a write-off', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $invoice = writableInvoice(20000);

    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $tieOut = app(BooksReconciliationService::class)->glTieOut();

    // The GL's AR balance and the AR the invoices imply must still agree. Without excluding
    // written_off from the expectation this raises a false delta on every written-off debt.
    expect($tieOut['configured'])->toBeTrue()
        ->and(abs((float) $tieOut['ar']['delta']))->toBeLessThan(0.02);
});

it('drops out of AR aging, because the debt is off the books', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $invoice = writableInvoice(20000);

    $before = app(ReportService::class)->arAgingBuckets(CarbonImmutable::parse('2026-06-15'));
    expect(array_sum(array_column($before, 'total')))->toBe(20000.0);

    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    $after = app(ReportService::class)->arAgingBuckets(CarbonImmutable::parse('2026-06-15'));
    expect(array_sum(array_column($after, 'total')))->toBe(0.0);
});

/* ---- the guards ------------------------------------------------------------ */

it('refuses to write off more than is outstanding', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $invoice = writableInvoice(20000);

    expect(fn () => app(WriteOffInvoiceService::class)->write($invoice, [
        'amount' => 25000, 'reason' => 'other',
    ]))->toThrow(DomainException::class);

    expect(InvoiceWriteOff::count())->toBe(0);
});

it('refuses an invoice with nothing outstanding, and one already written off', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $settled = writableInvoice(20000, ['status' => 'paid', 'paid_amount' => 20000, 'balance' => 0]);
    $live = writableInvoice(20000);

    expect(fn () => app(WriteOffInvoiceService::class)->write($settled, ['reason' => 'other']))
        ->toThrow(DomainException::class);

    app(WriteOffInvoiceService::class)->write($live, ['reason' => 'other']);

    expect(fn () => app(WriteOffInvoiceService::class)->write($live->fresh(), ['reason' => 'other']))
        ->toThrow(DomainException::class);
});

it('refuses a write-off dated into a closed accounting period', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $year = FiscalYear::create(['year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
    AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 3,
        'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31', 'status' => 'closed',
    ]);

    $invoice = writableInvoice(20000);

    // Guarded in the SERVICE. Without it the row commits, the operator sees "Saved", and the entry
    // is refused inside the best-effort sync job that only logs — the exact bug the posting-date
    // invariant exists to stop.
    expect(fn () => app(WriteOffInvoiceService::class)->write($invoice, [
        'entry_date' => '2026-03-15', 'reason' => 'other',
    ]))->toThrow(DomainException::class);

    expect(InvoiceWriteOff::count())->toBe(0);
});

/* ---- partial + recovery ---------------------------------------------------- */

it('leaves an invoice live when only part of the debt is written off', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $invoice = writableInvoice(20000);

    app(WriteOffInvoiceService::class)->write($invoice, ['amount' => 5000, 'reason' => 'settled_short']);

    // Writing off 5,000 of 20,000 does not mean the other 15,000 stopped being owed.
    expect($invoice->fresh()->status)->toBe('overdue')
        ->and((float) InvoiceWriteOff::sole()->amount)->toBe(5000.0);
});

it('reverses a recovered debt and voids its ledger entry, keeping both decisions', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $invoice = writableInvoice(20000);

    $service = app(WriteOffInvoiceService::class);
    $writeOff = $service->write($invoice, ['reason' => 'tenant_absconded']);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $service->reverse($writeOff);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect($invoice->fresh()->status)->not->toBe('written_off')
        // Soft-deleted, not erased: an auditor sees the debt was written off and later recovered.
        ->and(InvoiceWriteOff::withTrashed()->count())->toBe(1)
        ->and(InvoiceWriteOff::count())->toBe(0);

    // And the ledger no longer carries the relief.
    $ar = app(AccountResolver::class)->account('accounts_receivable');
    $closing = app(LedgerReportService::class)->accountLedger($ar)['closing'];
    expect(abs((float) $closing - 20000.0))->toBeLessThan(0.02);
});

it('cannot be written off twice by two concurrent operators', function () {
    // The guard is a lock-and-recheck inside the transaction; a sequential test cannot reproduce a
    // race, but it can hold the line that writing off an already-retired invoice is refused.
    CarbonImmutable::setTestNow('2026-06-15');
    $invoice = writableInvoice(20000);

    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'other']);

    expect(fn () => app(WriteOffInvoiceService::class)->write($invoice->fresh(), ['reason' => 'other']))
        ->toThrow(DomainException::class)
        ->and(round((float) DB::table('invoice_write_offs')->sum('amount'), 2))->toBe(20000.0);
});
