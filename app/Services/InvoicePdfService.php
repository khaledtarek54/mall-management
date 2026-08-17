<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Settings\TaxSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class InvoicePdfService
{
    public function build(Invoice $invoice): string
    {
        $invoice->loadMissing(['items', 'tenant', 'lease.unit.asset']);

        $isRtl = app()->getLocale() === 'ar';

        // The seller particulars a tax invoice is legally required to carry. Operator-level, not
        // per-property: Eltizam is one registered entity operating several malls, so the seller is
        // the operator and the building is only the trading address.
        $tax = app(TaxSettings::class);

        $html = View::make('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->tenant,
            'lease' => $invoice->lease,
            'unit' => $invoice->lease?->unit,
            'asset' => $invoice->lease?->unit?->asset,
            'sellerTrn' => trim($tax->seller_tax_registration_number),
            'sellerLegalName' => trim($tax->seller_legal_name),
            'vatSummary' => self::vatSummary($invoice),
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

    /**
     * Taxable value and tax, grouped by the rate each line was billed at.
     *
     * A tax invoice needs the split, not just one VAT total: base rent is exempt while service
     * charge is standard-rated, so a single invoice routinely carries both, and the tenant's
     * accountant has to know which part of it carries claimable input tax.
     *
     * Read off the LINES, which hold the rate they were issued at — never recomputed from today's
     * `TaxSettings`. An invoice keeps the rate it was billed at, so re-deriving it would silently
     * restate every historical document the day the standard rate changes.
     *
     * @return list<array{rate: float, base: float, vat: float}>
     */
    private static function vatSummary(Invoice $invoice): array
    {
        return $invoice->items
            ->groupBy(fn (InvoiceItem $item): string => (string) round((float) $item->vat_rate, 2))
            ->map(fn (Collection $group, string $rate): array => [
                'rate' => (float) $rate,
                'base' => round((float) $group->sum(fn (InvoiceItem $i) => (float) $i->amount), 2),
                'vat' => round((float) $group->sum(fn (InvoiceItem $i) => (float) $i->vat_amount), 2),
            ])
            ->sortByDesc('rate')
            ->values()
            ->all();
    }
}
