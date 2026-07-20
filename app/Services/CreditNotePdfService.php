<?php

namespace App\Services;

use App\Models\CreditNote;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a CREDIT NOTE (إشعار دائن) — the printable/downloadable sales-return document a tenant
 * receives when a charge is reduced or refunded. Mirrors InvoicePdfService (same Mpdf config + RTL
 * font switch). Strictly READ-ONLY: it renders the note's line items + derived AR figures and never
 * touches AR or the GL.
 */
class CreditNotePdfService
{
    public function build(CreditNote $note): string
    {
        $note->loadMissing(['items', 'tenant', 'invoice', 'lease.unit.asset']);

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('pdf.credit-note', [
            'note' => $note,
            'tenant' => $note->tenant,
            'invoice' => $note->invoice,
            'asset' => $note->lease?->unit?->asset,
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

    public function filename(CreditNote $note): string
    {
        return $note->number.'.pdf';
    }
}
