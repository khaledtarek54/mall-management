<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Services\VoidInvoiceService;
use App\Support\LedgerTrail;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The document → ledger trail (change-impact plan, phase 2 / F2 + F3).
 *
 * THE GAP. Every part of this was in the database — `journal_entries.source_type/source_id`,
 * `reversal_of_id`, the lines — and none of it was on any screen. An operator could not see an
 * invoice's journal entry and an accountant could not get from an entry back to its document.
 *
 * It matters here more than in a system that posts once: Atriom's ledger is DERIVED, so changing a
 * posted document makes a queued job void the entry and post a fresh one, described as "Superseded
 * by an updated document", with nothing told to anyone. The correction is real, correct, and
 * completely silent.
 *
 * These drive the REAL sweep rather than LedgerPoster, so the trail is asserted against entries
 * that production actually produced.
 */
function trailInvoice(float $total = 1000): Invoice
{
    $lease = makeLease(makeUnit(makeAsset()));

    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(15)->toDateString(),
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        // Header figures are re-derived by recomputeTotals() below; these only satisfy NOT NULL.
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'balance' => 0,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'base_rent',
        'description' => 'Rent',
        'amount' => $total,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => $total,
    ]);

    $invoice->recomputeTotals();

    return $invoice->refresh();
}

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
});

it('reports an unposted document as pending rather than as nothing', function () {
    $invoice = trailInvoice();

    $trail = LedgerTrail::for($invoice);

    expect($trail['posts'])->toBeTrue()
        ->and($trail['entry'])->toBeNull()
        // The distinction that matters to an operator: "not posted yet" is the system working,
        // and reads very differently from "this document has no ledger effect".
        ->and($trail['drifted'])->toBeTrue();
});

it('shows the posted entry and its lines once the sweep has run', function () {
    $invoice = trailInvoice(1000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $trail = LedgerTrail::for($invoice->refresh());

    expect($trail['entry'])->not->toBeNull()
        ->and($trail['entry']->status)->toBe('posted')
        ->and($trail['drifted'])->toBeFalse()
        ->and($trail['reversal'])->toBeNull();

    $rows = LedgerTrail::lineRows($trail['entry']);

    expect($rows)->toHaveCount(2)                                   // Dr AR / Cr rent revenue
        ->and(implode("\n", $rows))->toContain('1,000.00');
});

it('shows the reversal chain after a void — the correction that used to be silent', function () {
    $invoice = trailInvoice(1000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $original = LedgerTrail::for($invoice->refresh())['entry'];
    expect($original->status)->toBe('posted'); // precondition, or the assertions below prove nothing

    app(VoidInvoiceService::class)->void($invoice->refresh(), 'Billed to the wrong tenant');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $trail = LedgerTrail::for($invoice->refresh());

    expect($trail['entry']->id)->toBe($original->id)
        ->and($trail['entry']->status)->toBe('void')
        // The point of the screen: the operator can see WHICH entry reversed it, on what date.
        ->and($trail['reversal'])->not->toBeNull()
        ->and($trail['reversal']->reversal_of_id)->toBe($original->id)
        ->and($trail['drifted'])->toBeFalse();
});

it('flags a document that has drifted since it was posted', function () {
    $invoice = trailInvoice(1000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(LedgerTrail::for($invoice->refresh())['drifted'])->toBeFalse(); // control

    // A late fee is the real-world case: it rewrites an issued invoice's total via saveQuietly,
    // which fires no model event, so the entry is stale until the next sweep. Until now nothing
    // anywhere said so.
    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'late_fee', 'description' => 'Late fee',
        'amount' => 50, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50,
    ]);
    $invoice->recomputeTotals();

    expect(LedgerTrail::for($invoice->refresh())['drifted'])->toBeTrue();

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(LedgerTrail::for($invoice->refresh())['drifted'])->toBeFalse();
});

it('says plainly when a document does not reach the ledger at all', function () {
    // A lease is not a posting source. Without this branch the panel would render "not posted yet"
    // for it, which is a promise the system will never keep.
    $lease = makeLease(makeUnit(makeAsset()));

    $trail = LedgerTrail::for($lease);

    expect($trail['posts'])->toBeFalse()
        ->and($trail['entry'])->toBeNull()
        ->and($trail['drifted'])->toBeFalse();
});

it('keeps every entry a document has ever had, so the history is auditable', function () {
    $invoice = trailInvoice(1000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // Change the total, which the sweep resolves by voiding the stale entry and posting a new one.
    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'service_charge', 'description' => 'CAM',
        'amount' => 200, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 200,
    ]);
    $invoice->recomputeTotals();
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $trail = LedgerTrail::for($invoice->refresh());

    expect(count($trail['history']))->toBeGreaterThanOrEqual(2)
        ->and($trail['entry']->status)->toBe('posted')
        ->and((float) $trail['entry']->lines->sum('debit'))->toBe(1200.0)
        // Both remain on the books. A void is never an erase — that is what makes the derived
        // ledger auditable rather than merely correct.
        ->and(JournalEntry::where('source_type', $invoice->getMorphClass())
            ->where('source_id', $invoice->id)->where('status', 'void')->exists())->toBeTrue();
});
