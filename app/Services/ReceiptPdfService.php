<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Payment;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;

/**
 * Renders a payment RECEIPT VOUCHER (سند قبض) — the printable/downloadable proof handed to a tenant
 * who paid cash/cheque/transfer at the office. Typeset through `App\Support\Pdf\PdfDocument`, the one
 * renderer every issued document shares. Strictly READ-ONLY: it renders the payment amount + its
 * invoice allocation and never
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
            ...IssuingEntity::forView($asset),
        ];
    }

    /**
     * The receipt as a PDF, in the language the PAYER reads.
     *
     * The proof a tenant screenshots and files, so the language is theirs rather than the
     * cashier's — see {@see DocumentLocale}.
     */
    public function build(Payment $payment, ?string $locale = null): string
    {
        return $this->document($payment, $locale)->render();
    }

    /**
     * The configured document, before mpdf touches it.
     *
     * Split from {@see build()} so a test can read the HTML this service actually produces —
     * including the locale it resolved — rather than re-wiring the same builder in the test and
     * proving only that the test agrees with itself. `TaxInvoiceSellerParticularsTest` kept its own
     * copy of `viewData()` once and reproduced the service's bugs faithfully instead of catching
     * them; a second copy of the BUILDER is the same mistake one layer out.
     */
    public function document(Payment $payment, ?string $locale = null): PdfDocument
    {
        return PdfDocument::make('payments.receipt')
            ->locale(DocumentLocale::resolve($locale, $payment->tenant))
            ->data(fn (): array => $this->viewData($payment))
            ->reference($payment->reference)
            ->bleed()
            // A payment that failed, bounced or was refunded is not a receipt for anything.
            // Stamped rather than merely flagged: this is the document a tenant produces to prove
            // they paid, so one circulating unmarked is the most expensive kind of stale paper in
            // this system — a returned cheque still reads as settlement at arm's length.
            ->watermark(fn (): ?string => $payment->isReversed()
                ? __("admin.statuses.payment.{$payment->status}")
                : null);
    }

    public function filename(Payment $payment): string
    {
        return $payment->reference.'.pdf';
    }
}
