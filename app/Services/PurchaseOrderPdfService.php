<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a PURCHASE ORDER (أمر شراء) — the numbered, itemized document sent to the supplier once a
 * request is ordered (FR-PROC). Mirrors ReceiptPdfService / InvoicePdfService (same Mpdf config + RTL
 * font switch). Strictly READ-ONLY: it renders the ordered lines + their prices and touches nothing.
 *
 * A PO is not a tax invoice — it carries no VAT breakdown (that arrives on the vendor's bill). It is
 * the operator's committed intent to buy, at the prices approved, from the named vendor.
 */
class PurchaseOrderPdfService
{
    public function build(PurchaseRequest $request): string
    {
        $request->loadMissing(['asset', 'vendor', 'warehouse', 'lines.item', 'orderedBy', 'requestedBy']);

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('purchase-orders.pdf', [
            'po' => $request,
            'asset' => $request->asset,
            'vendor' => $request->vendor,
            'isRtl' => $isRtl,
        ])->render();

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

    /** Filename off the PO number (falls back to the requisition reference if somehow unordered). */
    public function filename(PurchaseRequest $request): string
    {
        $id = $request->po_number ?: $request->reference;

        return 'PO-'.str_replace(['/', ' '], '-', (string) $id).'.pdf';
    }
}
