<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;

/**
 * Renders a PURCHASE ORDER (أمر شراء) — the numbered, itemized document sent to the supplier once a
 * request is ordered (FR-PROC). Typeset through `App\Support\Pdf\PdfDocument`, the one renderer
 * every issued document shares. Strictly READ-ONLY: it renders the ordered lines + their prices and
 * touches nothing.
 *
 * A PO is not a tax invoice — it carries no VAT breakdown (that arrives on the vendor's bill). It is
 * the operator's committed intent to buy, at the prices approved, from the named vendor.
 */
class PurchaseOrderPdfService
{
    /**
     * The purchase order as a PDF, in the language the SUPPLIER reads.
     *
     * This one leaves the building toward a counterparty who never sees the panel, so the operator's
     * UI language is the least relevant answer available — a local contractor and an international
     * lift maintainer want opposite ones, from the same screen, on the same afternoon.
     */
    public function build(PurchaseRequest $request, ?string $locale = null): string
    {
        return $this->document($request, $locale)->render();
    }

    /**
     * The configured document, before mpdf touches it — the seam a test reads the HTML from, as on
     * the four money documents. See {@see InvoicePdfService::document()}.
     */
    public function document(PurchaseRequest $request, ?string $locale = null): PdfDocument
    {
        $request->loadMissing(['asset', 'vendor', 'warehouse', 'lines.item', 'orderedBy', 'requestedBy']);

        return PdfDocument::make('purchase-orders.pdf')
            ->locale(DocumentLocale::resolve($locale, $request->vendor))
            ->data(fn (): array => [
                'po' => $request,
                'asset' => $request->asset,
                'vendor' => $request->vendor,
                ...IssuingEntity::forView($request->asset),
            ])
            ->reference($request->po_number ?: $request->reference)
            ->bleed();
    }

    /** Filename off the PO number (falls back to the requisition reference if somehow unordered). */
    public function filename(PurchaseRequest $request): string
    {
        $id = $request->po_number ?: $request->reference;

        return 'PO-'.str_replace(['/', ' '], '-', (string) $id).'.pdf';
    }
}
