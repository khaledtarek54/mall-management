<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class InvoicePdfService
{
    public function build(Invoice $invoice): string
    {
        $invoice->loadMissing(['items', 'tenant', 'lease.unit.asset']);

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->tenant,
            'lease' => $invoice->lease,
            'unit' => $invoice->lease?->unit,
            'asset' => $invoice->lease?->unit?->asset,
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

    public function filename(Invoice $invoice): string
    {
        return $invoice->number.'.pdf';
    }
}
