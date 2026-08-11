<?php

use App\Filament\Imports\OpeningInvoiceImporter;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountingSeeder;
use Filament\Actions\Imports\Models\Import;

/**
 * Opening AR must arrive as documents that age, and must not post revenue twice.
 *
 * Before this there was **no way to load opening receivables at all** — the GL side could be a
 * manual journal, but the tenant side was hand-keyed invoice by invoice, which is why the cut-over
 * was the go-live blocker nobody had scoped.
 *
 * Two properties matter, and they pull against each other:
 *
 *  1. **They must be real invoices.** Aging, dunning, statements and per-invoice payment
 *     allocation all work on documents. A lump-sum balance has no number to quote to a retailer
 *     who disputes it, no due date to age against, and nothing for a payment to allocate to.
 *  2. **They must not post.** The revenue was earned in the previous system and is already in the
 *     accountant's opening journal entry; posting again would recognise it twice and double AR.
 *
 * The reconciliation is what proves the migration: `glTieOut()` counts these invoices in
 * `expectedAr` while the accountant's entry supplies GL AR, so the tie-out going square is the
 * statement "what I loaded equals what my accountant says I have".
 */
beforeEach(function () {
    $this->seed(AccountingSeeder::class);

    $this->asset = makeAsset(['code' => 'MALL']);
    $this->unit = makeUnit($this->asset, ['code' => 'A-101', 'status' => 'vacant']);
    $this->tenant = makeTenant(['email' => 'shop@brand.test']);
    $this->lease = makeLease($this->unit, $this->tenant);

    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'opening.csv',
        'file_path' => 'opening.csv',
        'importer' => OpeningInvoiceImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => \App\Models\User::factory()->create()->id,
    ]);
});

function importOpeningRow(array $row): void
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();

    (new OpeningInvoiceImporter(test()->import, $columnMap, []))($row);
}

function openingRow(array $overrides = []): array
{
    return array_merge([
        'asset_code' => 'MALL',
        'tenant_email' => 'shop@brand.test',
        'number' => 'OLD-SYS/2025/0417',
        'issue_date' => '2025-11-01',
        'due_date' => '2025-11-15',
        'amount' => '10000',
        'vat_amount' => '1400',
        'description' => 'November service charge',
    ], $overrides);
}

it('loads an opening receivable as a real invoice with its original number', function () {
    importOpeningRow(openingRow());

    $invoice = Invoice::where('number', 'OLD-SYS/2025/0417')->first();

    // The operator's own number, carried verbatim — it is the number on the paperwork the retailer
    // is being chased for, so inventing a new one makes the first collections call unanswerable.
    expect($invoice)->not->toBeNull()
        ->and($invoice->is_opening_balance)->toBeTrue()
        ->and((float) $invoice->total)->toBe(11400.0)
        ->and((float) $invoice->balance)->toBe(11400.0)
        ->and($invoice->tenant_id)->toBe($this->tenant->id);
});

it('gives it a line, so the tenant can see what they are being chased for', function () {
    importOpeningRow(openingRow());

    $invoice = Invoice::where('number', 'OLD-SYS/2025/0417')->first();

    // A header with no items renders an empty PDF and leaves InvoiceItemSettlement nothing to
    // derive against when the debt is part-paid.
    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first()->description)->toBe('November service charge')
        ->and((float) $invoice->items->first()->amount)->toBe(10000.0);
});

it('ages like any other debt', function () {
    importOpeningRow(openingRow());

    $invoice = Invoice::where('number', 'OLD-SYS/2025/0417')->first();

    // The whole reason for open items rather than a balance.
    expect($invoice->due_date->toDateString())->toBe('2025-11-15')
        ->and($invoice->daysOverdue())->toBeGreaterThan(0);
});

it('posts NOTHING to the general ledger', function () {
    importOpeningRow(openingRow());
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $invoice = Invoice::where('number', 'OLD-SYS/2025/0417')->first();

    // The revenue was earned in the previous system and is already in the accountant's opening
    // entry. Posting here would recognise it twice and inflate AR to double the debt.
    expect(JournalEntry::where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->id)
        ->exists())->toBeFalse();
});

it('still posts a NORMAL invoice — the paired control', function () {
    // Without this, "posts nothing" would pass just as happily if posting were broken outright,
    // which is the failure mode this whole test file would otherwise bless.
    $normal = makeInvoice($this->lease, [
        'status' => 'issued',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
    ]);
    \App\Models\InvoiceItem::create([
        'invoice_id' => $normal->id,
        'type' => 'base_rent',
        'description' => 'Rent',
        'amount' => 5000,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 5000,
    ]);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(JournalEntry::where('source_type', $normal->getMorphClass())
        ->where('source_id', $normal->id)
        ->exists())->toBeTrue();
});

it('ties out against the accountant opening entry — the whole cutover, end to end', function () {
    // 1. The operator imports the open items.
    importOpeningRow(openingRow());

    // 2. The accountant posts the opening trial balance by hand: Dr AR / Cr Opening Equity, for
    //    the same total. This is the real sequence, and it is the only thing that puts an opening
    //    receivable into the GL — the invoices deliberately post nothing.
    $accounts = app(\App\Services\Accounting\AccountResolver::class);
    app(\App\Services\Accounting\JournalPostingService::class)->post([
        'entry_date' => '2026-01-01',
        'description' => 'Opening balances at cutover',
        'lines' => [
            ['ledger_account_id' => $accounts->id('accounts_receivable'), 'debit' => 11400, 'credit' => 0],
            ['ledger_account_id' => $accounts->id('retained_earnings'), 'debit' => 0, 'credit' => 11400],
        ],
    ]);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $tieOut = app(BooksReconciliationService::class)->glTieOut();

    // Square. THIS is the migration's proof: the receivables loaded into the sub-ledger equal the
    // receivables the accountant says exist. Had the invoices also posted, AR would be 22,800 and
    // the delta would be the whole opening balance again.
    expect($tieOut['configured'])->toBeTrue()
        ->and($tieOut['ar']['delta'])->toBe(0.0);
});

it('is idempotent — re-running the cutover file does not double the debt', function () {
    importOpeningRow(openingRow());
    importOpeningRow(openingRow());

    // Keyed on the operator's invoice number, which is unique in their books and in this table.
    expect(Invoice::where('number', 'OLD-SYS/2025/0417')->count())->toBe(1)
        ->and(Invoice::where('is_opening_balance', true)->sum('total'))->toEqual(11400.0);
});

it('refuses a row for a property the importer cannot see', function () {
    $other = makeAsset(['code' => 'POINT']);
    makeUnit($other, ['code' => 'B-1', 'status' => 'vacant']);

    auth()->login(makeUser('manager', [$this->asset->id]));

    importOpeningRow(openingRow(['asset_code' => 'POINT', 'number' => 'OTHER/1']));

    expect(Invoice::where('number', 'OTHER/1')->exists())->toBeFalse();
});

it('skips a tenant with no lease at that property', function () {
    $stranger = makeTenant(['email' => 'stranger@brand.test']);

    importOpeningRow(openingRow(['tenant_email' => $stranger->email, 'number' => 'NOLEASE/1']));

    // lease.unit is how every report reaches the property dimension — an invoice with no lease is
    // invisible to per-property AR and to the owner statement, so it must not be created at all.
    expect(Invoice::where('number', 'NOLEASE/1')->exists())->toBeFalse();
});
