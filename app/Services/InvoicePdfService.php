<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\IssuingEntity;
use App\Support\VatSummary;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class InvoicePdfService
{
    /**
     * Everything the invoice template needs, resolved once.
     *
     * Public and separate from {@see build()} because mpdf is a renderer and the DOCUMENT is what
     * anyone wants to assert on — so a test can read the HTML without re-deriving the data. It used
     * to re-derive it: `TaxInvoiceSellerParticularsTest` kept its own copy of this array, which
     * meant it reproduced the service's bugs faithfully instead of catching them.
     */
    public function viewData(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'tenant', 'asset', 'lease.unit.floor', 'unitOwnership.unit.floor']);

        // An invoice's context is its AGREEMENT, and since module 37 that is a lease OR a unit
        // ownership — `invoices.lease_id` became nullable when owners started being billed for
        // صيانة. Resolving only through the lease left every assessment invoice with no unit, no
        // property and therefore no issuer block, and the template then dereferenced the null lease
        // and 500'd on every path that renders a PDF (list, edit, portal, API).
        // The property comes from the invoice's OWN `asset_id`, not from walking the agreement —
        // `CreditNotePdfService::data()` left the reasoning for exactly this on 2026-08-15, and it
        // applies here verbatim: the chain answers NULL for a document whose `lease_id` is null.
        // `asset_id` is NOT NULL and stamped `withTrashed()`, so it survives a soft-deleted unit.
        $ownership = $invoice->unitOwnership;
        $unit = $invoice->lease?->unit ?? $ownership?->unit;
        $asset = $invoice->asset ?? $unit?->asset;

        return [
            'invoice' => $invoice,
            'tenant' => $invoice->tenant,
            'lease' => $invoice->lease,
            'ownership' => $ownership,
            'unit' => $unit,
            'asset' => $asset,
            // The seller particulars a tax invoice is legally required to carry, and the
            // taxable-value split its reader needs. Both shared with the credit note, which is the
            // same kind of document pointing the other way.
            ...IssuingEntity::forView($asset),
            'vatSummary' => VatSummary::forItems($invoice->items),
        ];
    }

    public function build(Invoice $invoice): string
    {
        $data = $this->viewData($invoice);
        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('invoices.pdf', $data)->render();

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 14,
            'default_font' => $isRtl ? 'xbriyaz' : 'dejavusans',
            'default_font_size' => 10.5,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'useSubstitutions' => true,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetDirectionality($isRtl ? 'rtl' : 'ltr');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->number.'.pdf';
    }
}
