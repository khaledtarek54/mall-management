<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Models\JournalEntry;
use App\Models\UnitOwnership;
use App\Services\Accounting\FiscalCalendar;
use App\Services\BillUnitOwnershipsService;
use App\Services\CreditNoteService;
use App\Services\WriteOffInvoiceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Everything DOWNSTREAM of an owner invoice keeps its property too.
 *
 * Phase 2a put `asset_id` on `invoices` because four sites derived an invoice's property by walking
 * `lease -> unit -> asset`, which returns null the moment `lease_id` is nullable. Three documents
 * that hang off an invoice have the identical shape and were missed: a CREDIT NOTE, a WRITE-OFF and
 * the property scope a payment VOID computes. Each of them reached the property through a different
 * chain that was still valid for every row that existed at the time — which is exactly why the gates
 * stayed green.
 *
 * A document with a null property dimension is not loud. It posts, it balances, it ties out — and it
 * is absent from that mall's P&L and from its owner's statement.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'DS']);
    $this->buyer = makeTenant(['party_type' => PartyType::UnitOwner->value]);

    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset)->id,
        'tenant_id' => $this->buyer->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    Charge::create([
        'unit_ownership_id' => $ownership->id,
        'name' => 'Service charge', 'type' => 'service_charge',
        'amount' => 5000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'is_active' => true, 'start_date' => '2026-01-01',
    ]);

    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $this->invoice = Invoice::query()->where('unit_ownership_id', $ownership->id)->firstOrFail();
});

/** A draft note against the owner invoice, built the way every other credit-note test builds one. */
function ownerNote(float $total = 1000): CreditNote
{
    $note = CreditNote::create([
        'tenant_id' => test()->buyer->id,
        'invoice_id' => test()->invoice->id,
        'status' => 'draft',
        'issue_date' => '2026-03-15',
        'reason' => 'adjustment',
        'subtotal' => $total, 'vat_amount' => 0,
        'total' => $total, 'applied_amount' => 0, 'balance' => $total,
        'currency' => 'EGP',
    ]);

    CreditNoteItem::create([
        'credit_note_id' => $note->id,
        'description' => 'Adjustment',
        'amount' => $total, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $total,
    ]);

    return $note->refresh();
}

it('gives a credit note against an owner invoice the invoice property', function () {
    expect(ownerNote()->asset_id)->toBe($this->asset->id);
});

it('posts that credit note with a property dimension', function () {
    $note = ownerNote();
    app(CreditNoteService::class)->issue($note);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $entry = JournalEntry::query()
        ->where('source_type', CreditNote::class)->where('source_id', $note->id)
        ->firstOrFail();

    // A null here is the whole defect: the entry still balances and still ties out, and the mall's
    // P&L and its owner's statement simply never see it.
    expect($entry->asset_id)->toBe($this->asset->id);
});

it('gives a write-off against an owner invoice the invoice property', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, [
        'amount' => 500,
        'entry_date' => '2026-03-20',
        'reason' => 'uncollectible',
    ]);

    $writeOff = InvoiceWriteOff::query()->where('invoice_id', $this->invoice->id)->firstOrFail();

    // `invoice_write_offs.asset_id` is nullable, so a null stores silently — and then
    // PropertyIsolation's `InvoiceWriteOff => 'asset'` drops the row from every scoped read while
    // its journalizer resolves bad_debt_expense against no property at all.
    expect($writeOff->asset_id)->toBe($this->asset->id);
});
