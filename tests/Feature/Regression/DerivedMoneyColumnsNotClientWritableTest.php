<?php

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;

/**
 * A derived money column may not be written by whoever posts the form.
 *
 * Three instances of one pattern, all reachable by any holder of `invoices.edit` /
 * `credit_notes.edit` posting a crafted Livewire payload. The forms render these fields
 * `readOnly()`/`disabled()` and then `->dehydrated()` — which is an HTML attribute plus an explicit
 * opt-IN to the submitted payload. `readOnly` is styling; the model is the rule.
 *
 * **1. `balance` on an issued invoice.** The corrective hook short-circuited on
 * `isDirty(['subtotal','vat_amount','total'])`, so a payload changing `balance` ALONE returned
 * before the correction and persisted. The invoice then reads settled in the portal, in AR aging
 * (`arAgingBuckets()` filters `balance > 0`), in the overdue scan and on every collections screen —
 * while the GL still carries the AR debit. The comment directly above that hook read *"`readOnly()`
 * on the form is the UX; this is the rule"*. The rule had a hole.
 *
 * **2. `paid_amount`, the same way.** It is derived from FOUR settlement channels and only
 * `recomputeTotals()` may write it — which it does through `saveQuietly()`, so it never reaches
 * this hook. Anything arriving here dirtying it is therefore a payload, by construction.
 *
 * **3. A credit note's header.** `CreditNoteItem` had NO model hooks at all — no `booted`, no
 * `saved`, nothing — unlike `InvoiceItem`, so the totals were derived in the BROWSER only.
 * `CreditNoteService` then trusted the header verbatim, its own comment asserting "the totals are
 * already item-derived", which was true of the browser and nothing else. The result posts
 * `Dr Sales Returns / Cr AR` at a figure the document's own lines cannot reproduce.
 */
beforeEach(function () {
    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant());

    $this->invoice = makeInvoice($lease, [
        'status' => 'issued',
        'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
        'paid_amount' => 0, 'balance' => 11400,
    ]);

    InvoiceItem::create([
        'invoice_id' => $this->invoice->id,
        'type' => 'service_charge',
        'description' => 'Service charge',
        'amount' => 10000, 'vat_rate' => 14, 'vat_amount' => 1400, 'total' => 11400,
    ]);
});

it('refuses a payload that zeroes an issued invoice\'s balance', function () {
    // The exploit, exactly as a tampered Livewire payload arrives: mass-assign balance alone.
    $this->invoice->update(['balance' => 0]);

    expect((float) $this->invoice->fresh()->balance)->toBe(11400.0);
});

it('refuses a payload that inflates paid_amount', function () {
    // Marking an invoice paid without any payment, credit note, tenant credit or deposit behind it.
    $this->invoice->update(['paid_amount' => 11400]);

    $fresh = $this->invoice->fresh();

    expect((float) $fresh->paid_amount)->toBe(0.0)
        ->and((float) $fresh->balance)->toBe(11400.0);
});

it('still lets recomputeTotals write both — it is the single source of truth', function () {
    // The paired control. The guard must reject the CLIENT, not the mechanism: if this broke,
    // every real settlement would stop registering and the fix would be far worse than the bug.
    $this->invoice->credit_applied_amount = 11400;
    $this->invoice->saveQuietly();
    $this->invoice->recomputeTotals();

    $fresh = $this->invoice->fresh();

    expect((float) $fresh->paid_amount)->toBe(11400.0)
        ->and((float) $fresh->balance)->toBe(0.0);
});

it('still corrects a tampered header back to its line items', function () {
    // The behaviour that already worked, kept working.
    $this->invoice->update(['subtotal' => 1, 'vat_amount' => 0, 'total' => 1]);

    expect((float) $this->invoice->fresh()->total)->toBe(11400.0);
});

it('derives a credit note\'s header from its OWN lines, not the browser\'s arithmetic', function () {
    // The header claims 9,000; the single line says 1,000. Before this, `CreditNoteItem` had no
    // hooks and the header stood — so the GL posted a 9,000 reversal against a 1,000 document.
    $note = CreditNote::create([
        'invoice_id' => $this->invoice->id,
        'tenant_id' => $this->invoice->tenant_id,
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'reason' => 'billing_error',
        'subtotal' => 9000, 'vat_amount' => 0, 'total' => 9000,
        'applied_amount' => 0, 'balance' => 9000,
    ]);

    CreditNoteItem::create([
        'credit_note_id' => $note->id,
        'description' => 'Correction',
        'amount' => 1000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 1000,
    ]);

    $fresh = $note->fresh();

    expect((float) $fresh->subtotal)->toBe(1000.0)
        ->and((float) $fresh->total)->toBe(1000.0);
});

it('re-derives the credit note header when a line is removed', function () {
    // Deleting a line must move the header too — the failure mode `InvoiceItem` already guards and
    // `CreditNoteItem` did not.
    $note = CreditNote::create([
        'invoice_id' => $this->invoice->id,
        'tenant_id' => $this->invoice->tenant_id,
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'reason' => 'billing_error',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
        'applied_amount' => 0, 'balance' => 0,
    ]);

    $keep = CreditNoteItem::create([
        'credit_note_id' => $note->id, 'description' => 'Keep',
        'amount' => 400, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 400,
    ]);
    $drop = CreditNoteItem::create([
        'credit_note_id' => $note->id, 'description' => 'Drop',
        'amount' => 600, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 600,
    ]);

    expect((float) $note->fresh()->total)->toBe(1000.0);

    $drop->delete();

    expect((float) $note->fresh()->total)->toBe(400.0)
        ->and($keep->exists)->toBeTrue();
});

it('refuses to rewrite an issued invoice\'s number', function () {
    // The document number identifies the tax invoice the tenant holds and the ETA may have seen.
    // The form renders it `disabled()->dehydrated()`, which submits it, and ChangeImpact's stated
    // reason for not guarding it — "nothing rewrites it" — was simply not true of that payload.
    $original = $this->invoice->number;

    expect(fn () => $this->invoice->update(['number' => 'INV-FORGED-0001']))
        ->toThrow(DomainException::class);

    expect($this->invoice->fresh()->number)->toBe($original);
});

it('still lets a DRAFT invoice be corrected freely', function () {
    // Nothing above may leak into the draft path: a draft is a working document, and its number
    // and totals are still being decided.
    $draft = makeInvoice($this->invoice->lease, [
        'status' => 'draft',
        'subtotal' => 100, 'vat_amount' => 0, 'total' => 100,
        'paid_amount' => 0, 'balance' => 100,
    ]);

    $draft->update(['number' => 'INV-DRAFT-9999']);

    expect($draft->fresh()->number)->toBe('INV-DRAFT-9999');
});
