<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Settings\TaxSettings;
use App\Support\IssuingEntity;
use App\Support\VatSummary;
use Illuminate\Support\Facades\View;
use Tests\Support\TaxCatalogue;

/**
 * A document titled "Tax Invoice" must carry the seller's tax registration number.
 *
 * It did not. The seller block printed the property's name, address and city and nothing else —
 * there was no seller TRN anywhere in the data model, only a placeholder inside the (frozen)
 * e-invoicing settings that nothing rendered. **A tenant cannot support an input-VAT deduction
 * from a document with no supplier registration number on it**, so every invoice Atriom had ever
 * issued was unusable for the one purpose the title claims.
 *
 * There was also no taxable-value-per-rate summary, and that matters here specifically: base rent
 * is VAT-exempt while service charge is standard-rated, so one Atriom invoice routinely carries
 * both and a single "VAT: 1,400" line tells the tenant's accountant nothing about which part of
 * the 20,000 carries claimable input tax.
 *
 * Asserted against the rendered HTML rather than the PDF bytes: mpdf is a renderer, and what we
 * are pinning is the DOCUMENT's content.
 */
function taxInvoiceHtml(Invoice $invoice): string
{
    $invoice->loadMissing(['items', 'tenant', 'lease.unit.asset']);
    $asset = $invoice->lease?->unit?->asset;

    return View::make('invoices.pdf', [
        'invoice' => $invoice,
        'tenant' => $invoice->tenant,
        'lease' => $invoice->lease,
        'unit' => $invoice->lease?->unit,
        'asset' => $asset,
        ...IssuingEntity::forView($asset),
        'vatSummary' => VatSummary::forItems($invoice->items),
    ])->render();
}

beforeEach(function () {
    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL', 'name' => 'Atriom Walk'])), makeTenant());

    $this->invoice = makeInvoice($lease, [
        'status' => 'issued',
        'subtotal' => 20000, 'vat_amount' => 1400, 'total' => 21400, 'balance' => 21400,
    ]);

    // The ordinary Atriom invoice: exempt base rent AND standard-rated service charge on one
    // document. This is the mix the summary exists for.
    InvoiceItem::create([
        'invoice_id' => $this->invoice->id, 'type' => 'base_rent', 'description' => 'Base rent',
        'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);
    InvoiceItem::create([
        'invoice_id' => $this->invoice->id, 'type' => 'service_charge', 'description' => 'Service charge',
        'amount' => 10000, 'vat_rate' => 14, 'vat_amount' => 1400, 'total' => 11400,
    ]);
});

it('prints the seller tax registration number once configured', function () {
    $tax = app(TaxSettings::class);
    $tax->seller_tax_registration_number = '512-874-336';

    expect(taxInvoiceHtml($this->invoice->fresh()))->toContain('512-874-336');
});

it('prints the registered legal name when it differs from the property', function () {
    $tax = app(TaxSettings::class);
    $tax->seller_legal_name = 'Eltizam Property Management LLC';

    expect(taxInvoiceHtml($this->invoice->fresh()))->toContain('Eltizam Property Management LLC');
});

it('omits the line entirely rather than printing a placeholder', function () {
    // The reason the default is empty. A plausible-looking TRN is WORSE than none: it reads as
    // valid, the tenant files it, and it fails on audit — with Atriom's name on the document.
    $tax = app(TaxSettings::class);
    $tax->seller_tax_registration_number = '';

    $html = taxInvoiceHtml($this->invoice->fresh());

    expect($html)->not->toContain(__('admin.pdf.seller_trn'))
        // The shipped ETA placeholder must never reach a document.
        ->and($html)->not->toContain('123-456-789');
});

it('splits the taxable value by rate, so the tenant can claim the right input tax', function () {
    $html = taxInvoiceHtml($this->invoice->fresh());

    // 10,000 exempt + 10,000 standard-rated at 14% = 1,400. One "VAT" total cannot be checked
    // against anything; the split can.
    expect($html)->toContain(__('admin.pdf.vat_summary'))
        ->toContain(__('admin.pdf.standard_rated'))
        ->toContain(__('admin.pdf.exempt_or_zero'));
});

it('sums the rate summary back to the invoice totals', function () {
    // The arithmetic that makes the summary trustworthy: if these ever disagree with the header,
    // the tenant is looking at two contradictory statements of the same tax.
    $summary = VatSummary::forItems($this->invoice->fresh()->load('items')->items);

    expect(round(array_sum(array_column($summary, 'base')), 2))->toBe(20000.0)
        ->and(round(array_sum(array_column($summary, 'vat')), 2))->toBe(1400.0);
});

it('omits the summary on a single-rate invoice', function () {
    // Noise control: with one rate the totals block already says everything the summary would.
    $lease = makeLease(makeUnit(makeAsset(['code' => 'SOLO'])), makeTenant());
    $simple = makeInvoice($lease, [
        'status' => 'issued',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
    ]);
    InvoiceItem::create([
        'invoice_id' => $simple->id, 'type' => 'base_rent', 'description' => 'Base rent',
        'amount' => 5000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 5000,
    ]);

    expect(taxInvoiceHtml($simple->fresh()))->not->toContain(__('admin.pdf.vat_summary'));
});

it('reads each line\'s OWN rate rather than today\'s standard rate', function () {
    // An issued invoice keeps the rate it was billed at. Re-deriving the summary from
    // the tax catalogue would silently restate every historical document the day the rate changes —
    // the origination-only rule that governs VAT everywhere else in this codebase.
    TaxCatalogue::setStandardRate(20.0);

    $summary = VatSummary::forItems($this->invoice->fresh()->load('items')->items);

    expect(collect($summary)->pluck('rate')->all())->toBe([14.0, 0.0]);
});
