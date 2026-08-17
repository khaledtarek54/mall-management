<?php

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Services\CreditNotePdfService;
use App\Settings\TaxSettings;
use App\Support\VatSummary;
use Illuminate\Support\Facades\View;
use Tests\Support\TaxCatalogue;

/**
 * A credit note adjusts a tax invoice, so it must read as a tax document.
 *
 * The invoice was given the seller's registration number, registered legal name and
 * taxable-value-by-rate summary on 2026-08-12, on the reasoning that **a tenant cannot support an
 * input-VAT deduction from a document with no supplier registration number on it**
 * (`TaxInvoiceSellerParticularsTest`). Its peer got none of the three, and stayed that way for five
 * days — the mirror-image failure and arguably the worse one: a credit note is what the tenant uses
 * to REVERSE input tax they have already claimed, so an unidentifiable one leaves them holding a
 * deduction they cannot cleanly give back.
 *
 * This is the "enumerate ALL peers" miss this codebase has made before (the GL hardening note in
 * CLAUDE.md, where VendorBill went three weeks unnoticed). The fix is one shared implementation
 * each — `App\Support\IssuingEntity`, `App\Support\VatSummary` — so the pair cannot diverge again.
 *
 * Everything here goes through `CreditNotePdfService::data()`, the real service's own view data, and
 * asserts on rendered HTML rather than PDF bytes: mpdf is a renderer, and what is being pinned is
 * the DOCUMENT's content. Building the view data by hand in the test would pass whether or not the
 * service was ever fixed.
 */
function creditNoteHtml(CreditNote $note): string
{
    return View::make('pdf.credit-note', app(CreditNotePdfService::class)->data($note))->render();
}

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL', 'name' => 'Atriom Walk']);
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);

    $this->note = CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'lease_id' => $this->lease->id,
        'asset_id' => $this->asset->id,
        'status' => 'issued',
        'reason' => 'adjustment',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
        'issue_date' => now()->toDateString(),
        'currency' => 'EGP',
    ]);

    // The ordinary Atriom credit: exempt base rent AND standard-rated service charge reversed on one
    // note, because that is the mix the invoice it adjusts carried.
    CreditNoteItem::create([
        'credit_note_id' => $this->note->id, 'description' => 'Base rent credit',
        'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);
    CreditNoteItem::create([
        'credit_note_id' => $this->note->id, 'description' => 'Service charge credit',
        'amount' => 10000, 'vat_rate' => 14, 'vat_amount' => 1400, 'total' => 11400,
    ]);
});

it('prints the seller tax registration number once configured', function () {
    app(TaxSettings::class)->seller_tax_registration_number = '512-874-336';

    expect(creditNoteHtml($this->note->fresh()))->toContain('512-874-336');
});

it('prints the registered legal name when it differs from the property', function () {
    app(TaxSettings::class)->seller_legal_name = 'Eltizam Property Management LLC';

    expect(creditNoteHtml($this->note->fresh()))->toContain('Eltizam Property Management LLC');
});

it('omits the registration line entirely rather than printing a placeholder', function () {
    // The reason the default is empty. A plausible-looking TRN is WORSE than none: it reads as
    // valid, the tenant files it against their VAT return, and it fails on audit.
    app(TaxSettings::class)->seller_tax_registration_number = '';

    $html = creditNoteHtml($this->note->fresh());

    expect($html)->not->toContain(__('admin.pdf.seller_trn'))
        // The shipped ETA placeholder must never reach a document.
        ->and($html)->not->toContain('123-456-789');
});

it('splits the credited value by rate, so the tenant reverses the right input tax', function () {
    $html = creditNoteHtml($this->note->fresh());

    expect($html)->toContain(__('admin.pdf.vat_summary'))
        ->toContain(__('admin.pdf.standard_rated'))
        ->toContain(__('admin.pdf.exempt_or_zero'));
});

it('omits the summary on a single-rate note', function () {
    // Noise control, matching the invoice: with one rate the totals block already says everything.
    // This is also the control for the assertion above — a summary that never rendered at all would
    // satisfy that refusal just as happily.
    $simple = CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'lease_id' => $this->lease->id,
        'asset_id' => $this->asset->id,
        'status' => 'issued',
        'reason' => 'adjustment',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
        'issue_date' => now()->toDateString(),
        'currency' => 'EGP',
    ]);
    CreditNoteItem::create([
        'credit_note_id' => $simple->id, 'description' => 'Rent credit',
        'amount' => 5000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 5000,
    ]);

    expect(creditNoteHtml($simple->fresh()))->not->toContain(__('admin.pdf.vat_summary'));
});

it('reads each line\'s OWN rate rather than today\'s standard rate', function () {
    // Origination-only, exactly as on the invoice: a credit note reverses tax at the rate the
    // original document was billed at, so re-deriving from the catalogue would restate history the
    // day a rise takes effect — and would credit back tax that was never charged.
    TaxCatalogue::setStandardRate(20.0);

    $summary = VatSummary::forItems($this->note->fresh()->load('items')->items);

    expect(collect($summary)->pluck('rate')->all())->toBe([14.0, 0.0]);
});

it('sums the rate summary back to the note totals', function () {
    // The arithmetic that makes the summary trustworthy: if these ever disagree with the totals
    // block, the tenant is looking at two contradictory statements of the same tax.
    $summary = VatSummary::forItems($this->note->fresh()->load('items')->items);

    expect(round(array_sum(array_column($summary, 'base')), 2))->toBe(20000.0)
        ->and(round(array_sum(array_column($summary, 'vat')), 2))->toBe(1400.0);
});

it('names the property on a note that has no lease', function () {
    // The second half of the sweep. `CreditNotePdfService` resolved the property through
    // `$note->lease?->unit?->asset` — the chain migration 2026_08_15_130000 replaced with a
    // denormalized `asset_id` *because it answers NULL here*. A note against a unit-owner
    // assessment has no lease, so exactly those notes printed with no property name and an empty
    // address block, and the reader could not tell which mall issued the credit.
    $orphan = CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'lease_id' => null,
        'asset_id' => $this->asset->id,
        'status' => 'issued',
        'reason' => 'adjustment',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
        'issue_date' => now()->toDateString(),
        'currency' => 'EGP',
    ]);
    CreditNoteItem::create([
        'credit_note_id' => $orphan->id, 'description' => 'Assessment credit',
        'amount' => 3000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 3000,
    ]);

    // The precondition that makes this the real case and not a decorative one.
    expect($orphan->fresh()->lease)->toBeNull();

    expect(creditNoteHtml($orphan->fresh()))->toContain('Atriom Walk');
});

it('still builds a PDF', function () {
    // The renderer half: the additions above are new markup, and a template that throws would fail
    // every assertion here as an HTML string test without anyone learning the document stopped
    // printing.
    $pdf = app(CreditNotePdfService::class)->build($this->note->fresh());

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});
