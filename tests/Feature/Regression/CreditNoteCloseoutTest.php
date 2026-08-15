<?php

use App\Support\MorphMap;
use App\Models\AccountingPeriod;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\CreditNoteService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\VoidInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Module 07 close-out — the fixes surfaced by the gap-analysis sweep, each pinned:
 * A cancel-un-apply (no double sales-return, GL↔AR tie-out) · B cross-tenant guard ·
 * C property binding + cross-property guard · D delete-guard · E posting-date guard ·
 * F server-side total recompute · I guided reverse · the VAT-reversal real-path tie-out.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    app(FiscalCalendar::class)->ensureYear((int) now()->subMonthNoOverflow()->year);
});

/** An issued invoice with a single VAT-exempt rent line so the GL math is clean. */
function cnCloseInvoice($lease, float $rent): Invoice
{
    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
        'subtotal' => $rent, 'vat_amount' => 0, 'total' => $rent, 'balance' => $rent, 'paid_amount' => 0,
    ]);
    $invoice->items()->create([
        'description' => 'Rent', 'type' => 'base_rent', 'amount' => $rent, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $rent,
    ]);

    return $invoice;
}

/** An issued credit note carrying one line (net + optional VAT). */
function cnCloseNote($lease, float $net, float $vatRate = 0, ?string $issueDate = null): CreditNote
{
    $vat = round($net * $vatRate / 100, 2);
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'issued',
        'issue_date' => $issueDate ?? now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => $net, 'vat_amount' => $vat, 'total' => round($net + $vat, 2),
        'applied_amount' => 0, 'balance' => round($net + $vat, 2), 'currency' => 'EGP',
    ]);
    $note->items()->create([
        'description' => 'Overcharge', 'amount' => $net, 'vat_rate' => $vatRate, 'vat_amount' => $vat, 'total' => round($net + $vat, 2),
    ]);

    return $note;
}

it('A — un-applies credit on invoice cancel and ties AR out to the GL (no double sales-return)', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $invoice = cnCloseInvoice($lease, 10000);
    $note = cnCloseNote($lease, 3000);

    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());

    // Post every source through the real journalizers, then confirm the books tie out.
    foreach ([$invoice, $note] as $doc) {
        app(LedgerPoster::class)->sync($doc->fresh());
    }
    expect(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);

    // Cancel the invoice — the applied credit un-applies (the note re-opens), and the books must
    // STILL tie: the old design left the note applied AND issued a second note = a double return.
    app(VoidInvoiceService::class)->void($invoice->fresh(), 'wrong invoice');
    foreach ([$invoice, $note] as $doc) {
        app(LedgerPoster::class)->sync($doc->fresh());
    }

    $note->refresh();
    expect($note->status)->toBe('issued')
        ->and((float) $note->balance)->toBe(3000.0)                          // credit back on the ORIGINAL note
        ->and(CreditNoteApplication::where('credit_note_id', $note->id)->count())->toBe(0)
        ->and(CreditNote::count())->toBe(1)                                  // NO second note
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);
});

it('B — refuses applying a credit note across tenants', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $note = cnCloseNote($lease, 3000);

    // A DIFFERENT tenant's invoice (same property).
    $otherLease = makeLease(makeUnit($lease->unit->asset), makeTenant(), ['status' => 'active']);
    $otherInvoice = cnCloseInvoice($otherLease, 5000);

    app(CreditNoteService::class)->applyToInvoice($note, $otherInvoice);
})->throws(DomainException::class);

it('C — binds a standalone note to the invoice property on apply, then refuses a cross-property invoice', function () {
    $tenant = makeTenant();
    $leaseA = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $leaseB = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invA = cnCloseInvoice($leaseA, 5000);

    // A standalone (lease-less) note for the tenant — larger than invA so balance remains after apply.
    $note = CreditNote::create([
        'tenant_id' => $tenant->id, 'lease_id' => null, 'status' => 'issued',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 8000, 'vat_amount' => 0, 'total' => 8000, 'applied_amount' => 0, 'balance' => 8000, 'currency' => 'EGP',
    ]);

    app(CreditNoteService::class)->applyToInvoice($note, $invA->fresh()); // applies 5000, 3000 remains

    // The note adopted property A (so its sales-return posts to A's books, not the null bucket).
    expect($note->fresh()->lease_id)->toBe($leaseA->id);
    app(LedgerPoster::class)->sync($note->fresh());
    $entry = JournalEntry::where('source_type', MorphMap::alias(CreditNote::class))->where('source_id', $note->id)->where('status', 'posted')->with('lines')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->lines->pluck('asset_id')->unique()->all())->toBe([$leaseA->unit->asset_id]);

    // Now it can ONLY settle property-A invoices — a B invoice is refused.
    $invB = cnCloseInvoice($leaseB, 5000);
    app(CreditNoteService::class)->applyToInvoice($note->fresh(), $invB);
})->throws(DomainException::class);

it('D — refuses deleting a note whose credit is still applied', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $invoice = cnCloseInvoice($lease, 10000);
    $note = cnCloseNote($lease, 3000);
    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());

    $note->fresh()->delete();
})->throws(DomainException::class);

it('E — refuses issuing a credit note into a closed period', function () {
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
    AccountingPeriod::forDate($lastMonth)->update(['status' => 'closed']);

    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'draft',
        'issue_date' => $lastMonth->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'applied_amount' => 0, 'balance' => 1000, 'currency' => 'EGP',
    ]);

    app(CreditNoteService::class)->issue($note);
})->throws(DomainException::class);

it('I — reverses all applications: the note returns to available and the invoices re-open', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $inv1 = cnCloseInvoice($lease, 2000);
    $inv2 = cnCloseInvoice($lease, 2000);
    $note = cnCloseNote($lease, 5000);

    app(CreditNoteService::class)->applyToInvoice($note, $inv1->fresh()); // 2000
    app(CreditNoteService::class)->applyToInvoice($note->fresh(), $inv2->fresh()); // 2000
    expect((float) $note->fresh()->applied_amount)->toBe(4000.0);

    $reversed = app(CreditNoteService::class)->reverseAllApplications($note->fresh());

    expect($reversed)->toBe(4000.0)
        ->and($note->fresh()->status)->toBe('issued')
        ->and((float) $note->fresh()->balance)->toBe(5000.0)                 // fully restored
        ->and((float) $inv1->fresh()->balance)->toBe(2000.0)                // re-opened
        ->and((float) $inv2->fresh()->balance)->toBe(2000.0)
        ->and(CreditNoteApplication::where('credit_note_id', $note->id)->count())->toBe(0);
});

it('reverses output VAT on the GL through the real sweep (Dr VAT Payable + Dr Sales Returns / Cr AR)', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $invoice = cnCloseInvoice($lease, 11400);
    $note = cnCloseNote($lease, 1000, 14); // net 1000 + 140 VAT = 1140

    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh(), 1140);
    app(LedgerPoster::class)->sync($note->fresh());

    $entry = JournalEntry::where('source_type', MorphMap::alias(CreditNote::class))->where('source_id', $note->id)->where('status', 'posted')->with('lines')->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->lines->sum('debit'))->toBe(1140.0)   // 1000 sales-return + 140 VAT
        ->and((float) $entry->lines->sum('credit'))->toBe(1140.0); // AR (balanced)

    // The VAT is reversed to VAT Payable (not lumped into sales_returns) — the whole point.
    $accounts = app(\App\Services\Accounting\AccountResolver::class);
    $vatLine = $entry->lines->firstWhere('ledger_account_id', $accounts->id('vat_payable'));
    $srLine = $entry->lines->firstWhere('ledger_account_id', $accounts->id('sales_returns'));
    expect((float) $vatLine?->debit)->toBe(140.0)
        ->and((float) $srLine?->debit)->toBe(1000.0);
});

it('reverseAllApplications is a safe no-op on a legacy note (applied credit, no application rows)', function () {
    // Credit applied BEFORE the applications table existed has applied_amount > 0 but NO rows.
    // Reverse must NOT blindly zero the note (that would free it while its invoice keeps the credit
    // = double-count) — with no rows it derives $reversed = 0 → a no-op. (Backfill migration gives
    // real legacy notes their rows; this pins the corruption-proof fallback.)
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $invoice = cnCloseInvoice($lease, 10000);
    $note = cnCloseNote($lease, 3000);
    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());

    // Simulate the legacy shape: drop the application row but keep the aggregate columns.
    CreditNoteApplication::where('credit_note_id', $note->id)->forceDelete();

    $reversed = app(CreditNoteService::class)->reverseAllApplications($note->fresh());

    expect($reversed)->toBe(0.0)                                              // nothing to reverse
        ->and((float) $note->fresh()->applied_amount)->toBe(3000.0)          // note UNCHANGED (not freed)
        ->and((float) $note->fresh()->balance)->toBe(0.0)
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(3000.0); // invoice UNCHANGED
});

it('refuses FORCE-deleting a note whose credit is still applied (would strand the invoice credit)', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $invoice = cnCloseInvoice($lease, 10000);
    $note = cnCloseNote($lease, 3000);
    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());

    $note->fresh()->forceDelete();
})->throws(DomainException::class);

it('is safe against a double-apply via a stale instance (no over-application)', function () {
    // sqlite :memory: makes lockForUpdate a no-op, so drive the race deterministically: drain the
    // note in the DB, then apply again via a STALE in-memory copy — the service re-reads under lock.
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $invoice = cnCloseInvoice($lease, 10000);
    $note = cnCloseNote($lease, 3000);

    $stale = CreditNote::find($note->id);              // pre-drain snapshot
    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh(), 3000); // drains it in the DB

    $again = app(CreditNoteService::class)->applyToInvoice($stale, $invoice->fresh()); // stale copy

    expect($again)->toBe(0.0)                                                  // nothing re-applied
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(3000.0)  // not 6000
        ->and(CreditNoteApplication::where('credit_note_id', $note->id)->count())->toBe(1);
});
