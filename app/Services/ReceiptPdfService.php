<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Payment;
use App\Support\IssuingEntity;
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
    /**
     * Everything the receipt states, resolved once.
     *
     * Public and separate from `build()` for the reason `InvoicePdfService::viewData()` is: mpdf
     * returns a binary blob, so a test that only calls `build()` can assert `%PDF` and little else —
     * which is exactly how a receipt naming no mall passed for as long as it did.
     *
     * @return array<string, mixed>
     */
    public function viewData(Payment $payment): array
    {
        $payment->loadMissing(['tenant', 'invoices.asset', 'receiver']);

        // Brand off the first allocated invoice's mall (a receipt is issued at the property counter).
        // Read the invoice's OWN `asset_id`, never `lease?->unit?->asset`: `invoices.lease_id` is
        // nullable since module 37, so the chain answers null for a unit owner paying their صيانة
        // assessment — and the receipt then prints no property and no issuer. It degrades quietly
        // (the template is null-safe) which is why nobody reported it. `asset_id` is NOT NULL:
        // `Invoice::deriveAssetId()` stamps it with `withTrashed()` and the model refuses to save
        // without one.
        // …falling back to the cheque the payment came from. A cleared post-dated cheque lodged
        // without an invoice produces a CAPTURED payment with zero allocations — `lodgeSeries()`
        // creates exactly that, and it is the Egyptian year-of-cheques norm, not an edge case. Both
        // reachable surfaces are tenant-facing (portal `ViewPayment` and
        // `GET /api/v1/me/payments/{id}/receipt`, each gated on `isReceived()` alone), so without
        // this the tenant's own receipt names no mall and no issuer. `originatingAssetId()` exists
        // for precisely this state and had only one consumer, the journalizer.
        $asset = $payment->invoices->first()?->asset
            ?? Asset::find($payment->originatingAssetId());

        return [
            'payment' => $payment,
            'tenant' => $payment->tenant,
            'asset' => $asset,
            'isRtl' => app()->getLocale() === 'ar',
            ...IssuingEntity::forView($asset),
        ];
    }

    public function build(Payment $payment): string
    {
        $data = $this->viewData($payment);
        $isRtl = $data['isRtl'];

        $html = View::make('payments.receipt', $data)->render();

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
