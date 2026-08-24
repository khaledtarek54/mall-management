<?php

use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Services\Accounting\FiscalCalendar;
use App\Services\PostDatedChequeService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A receipt with nothing to allocate to still belongs to a property — and a VOID entry is history.
 *
 * Two findings from the same run of `atriom:preflight` (2026-08-19), and the second is a
 * consequence of fixing the first.
 *
 * ## 1. The receipt that belonged to no mall
 *
 * `payments` carries no `asset_id`: a receipt's books dimension is DERIVED from the invoices it
 * settles, which is right — a receipt belongs to the property whose debt it clears. The derivation
 * had one hole and it is reachable from the ordinary screens.
 *
 * A post-dated cheque may be recorded with **no invoice**. That is deliberate — the form requires a
 * tenant and not an invoice, because a cheque often arrives before the invoice it will eventually
 * settle. Clearing one produces a captured `Payment` with **zero allocations**, and the journalizer
 * then had nothing to derive from. Measured: `Dr bank 50,000 / Cr unearned revenue 50,000`, with
 * `asset_id` **NULL on the entry and on both lines**.
 *
 * The consequence is not cosmetic. `GenerateOwnerStatementRunService` scopes
 * `where('asset_id', $asset->id)`, so the landlord's own cash was **absent from the landlord's own
 * statement** — while the trial balance tied out and every reconciliation check passed.
 *
 * The property was never unknown. It is on the cheque.
 *
 * ## 2. The void entry the audit could not stop reporting
 *
 * Fixing (1) is what exposed (2). The ledger is DERIVED — `LedgerPoster::sync()` voids an entry that
 * no longer matches its document and posts a fresh one — so correcting the property left the old
 * property-less entry behind as a void row. `atriom:audit-property-dimension` counted it, for ever,
 * and offered a remedy that cannot be performed on a void entry ("correct a posted entry with a
 * reversing entry; edit an unposted one"). A check that fails with no available action is one people
 * learn to skip, which costs more than the rows it catches.
 */
beforeEach(function () {
    seedRoles();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'RCP']);
    $this->unit = makeUnit($this->asset, ['code' => 'R-1']);
    $this->lease = makeLease($this->unit, null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2035-12-31',
    ]);
    $this->actor = makeUser('super_admin');

    $this->cheque = function (?int $invoiceId, float $amount = 50_000): PostDatedCheque {
        return PostDatedCheque::create([
            'reference' => 'PDC-'.uniqid(),
            'asset_id' => $this->asset->id,
            'tenant_id' => $this->lease->tenant_id,
            'lease_id' => $this->lease->id,
            'invoice_id' => $invoiceId,
            'cheque_number' => 'CHQ-'.uniqid(),
            'bank_name' => 'CIB',
            'amount' => $amount,
            'currency' => 'EGP',
            'received_date' => now()->toDateString(),
            'cheque_date' => now()->toDateString(),
            'status' => PostDatedCheque::STATUS_HELD,
        ]);
    };

    /** The posted entry for the payment a cleared cheque produced, after the REAL sweep. */
    $this->entryFor = function (PostDatedCheque $cheque): JournalEntry {
        $this->artisan('accounting:sync-ledger', ['--all' => true]);

        return JournalEntry::where('source_type', 'payment')
            ->where('source_id', $cheque->fresh()->cleared_payment_id)
            ->where('status', 'posted')
            ->firstOrFail();
    };
});

it('files an on-account cheque receipt under the property the cheque names', function () {
    // The finding. Nothing to allocate to, so nothing to derive from — and the cheque knew all along.
    $cheque = ($this->cheque)(null);

    app(PostDatedChequeService::class)->clear($cheque, $this->actor);

    $entry = ($this->entryFor)($cheque);

    expect($entry->asset_id)->toBe($this->asset->id)
        // The LINES too. An entry keyed correctly whose lines are not still misses every report that
        // aggregates `journal_lines`, which is all of them.
        ->and($entry->lines->pluck('asset_id')->unique()->all())->toBe([$this->asset->id]);
});

it('still derives the property from the invoice when there is one — the control', function () {
    // Without this, a fallback that ignored the allocations entirely would look identical.
    $invoice = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id,
        'subtotal' => 50_000, 'vat_amount' => 0, 'total' => 50_000,
        'balance' => 50_000, 'paid_amount' => 0, 'status' => 'issued',
    ]);

    $cheque = ($this->cheque)($invoice->id);
    app(PostDatedChequeService::class)->clear($cheque, $this->actor);

    expect(($this->entryFor)($cheque)->asset_id)->toBe($this->asset->id);
});

it('leaves a genuinely cross-property receipt without one — the case that must NOT be collapsed', function () {
    // A receipt settling two malls' invoices belongs to neither, and `portfolioRowsWhenNull` is what
    // makes it visible on both. Filling it in with "whichever came first" would be worse than null,
    // because it would look right.
    $other = makeAsset(['code' => 'OTH']);
    $otherUnit = makeUnit($other, ['code' => 'O-1']);
    $otherLease = makeLease($otherUnit, $this->lease->tenant, [
        'status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2035-12-31',
    ]);

    $here = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'subtotal' => 1_000, 'vat_amount' => 0,
        'total' => 1_000, 'balance' => 1_000, 'paid_amount' => 0, 'status' => 'issued',
    ]);
    $there = makeInvoice($otherLease, [
        'asset_id' => $other->id, 'subtotal' => 1_000, 'vat_amount' => 0,
        'total' => 1_000, 'balance' => 1_000, 'paid_amount' => 0, 'status' => 'issued',
    ]);

    $payment = Payment::create([
        'reference' => Payment::generateReference(),
        'tenant_id' => $this->lease->tenant_id,
        'amount' => 2_000, 'currency' => 'EGP', 'method' => 'bank_transfer', 'status' => 'captured',
        'payment_date' => now()->toDateString(), 'received_by' => $this->actor->id,
    ]);
    $payment->invoices()->attach([
        $here->id => ['allocated_amount' => 1_000],
        $there->id => ['allocated_amount' => 1_000],
    ]);
    $payment->recomputeAllocatedInvoices();

    $this->artisan('accounting:sync-ledger', ['--all' => true]);

    $entry = JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)
        ->where('status', 'posted')->firstOrFail();

    expect($entry->asset_id)->toBeNull('a consolidated receipt was filed under one of its properties');
});

it('ignores a VOID property-less entry, and still catches a posted one', function () {
    // Paired, because an audit that ignored everything would satisfy the first half alone. The void
    // row is what every void-and-repost correction leaves behind, so counting it means a fixed
    // defect keeps failing the gate.
    $posted = JournalEntry::create([
        'number' => 'JE-AUDIT-1',
        'entry_date' => now()->toDateString(),
        'status' => 'posted',
        'asset_id' => null,
    ]);

    $this->artisan('atriom:audit-property-dimension')->assertFailed();

    $posted->forceFill(['status' => 'void'])->saveQuietly();

    $this->artisan('atriom:audit-property-dimension')->assertSuccessful();
});
