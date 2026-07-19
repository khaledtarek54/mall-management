<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a payment RECEIPT VOUCHER (سند قبض) — the printable/downloadable proof handed to a tenant
 * who paid cash/cheque/transfer at the office. Mirrors InvoicePdfService (same Mpdf config + RTL font
 * switch). Strictly READ-ONLY: it renders the payment amount + its invoice allocation and never
 * touches AR or the GL. A receipt is a cash-received acknowledgement, NOT a tax invoice — it carries
 * no VAT breakdown.
 */
class ReceiptPdfService
{
    public function build(Payment $payment): string
    {
        $payment->loadMissing(['tenant', 'invoices.lease.unit.asset', 'receiver']);

        $isRtl = app()->getLocale() === 'ar';

        // Brand off the first allocated invoice's mall (a receipt is issued at the property counter).
        $asset = $payment->invoices->first()?->lease?->unit?->asset;

        $html = View::make('payments.receipt', [
            'payment' => $payment,
            'tenant' => $payment->tenant,
            'asset' => $asset,
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

    public function filename(Payment $payment): string
    {
        return $payment->reference.'.pdf';
    }
}
