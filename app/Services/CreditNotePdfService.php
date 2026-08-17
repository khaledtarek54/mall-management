<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Support\IssuingEntity;
use App\Support\VatSummary;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a CREDIT NOTE (إشعار دائن) — the printable/downloadable sales-return document a tenant
 * receives when a charge is reduced or refunded. Mirrors InvoicePdfService (same Mpdf config + RTL
 * font switch). Strictly READ-ONLY: it renders the note's line items + derived AR figures and never
 * touches AR or the GL.
 *
 * **It is a tax document, and until 2026-08-17 it did not read as one.** The invoice was given the
 * seller's registration number, registered legal name and taxable-value-by-rate summary on
 * 2026-08-12, on the reasoning that a tenant cannot support an input-VAT deduction from a document
 * with no supplier registration number on it. Its peer got none of the three — and a credit note is
 * precisely what the tenant needs in order to REVERSE input VAT they have already claimed, so the
 * omission left them unable to substantiate the adjustment either way. Same rule, both directions:
 * the particulars come from {@see IssuingEntity} and the split from {@see VatSummary}, one
 * implementation each, so the pair cannot drift again.
 */
class CreditNotePdfService
{
    public function build(CreditNote $note): string
    {
        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('pdf.credit-note', $this->data($note))->render();

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

    /**
     * Everything the note states, before it becomes a PDF.
     *
     * Separated from {@see build()} for the reason the tenant statement is: a test that has to parse
     * mpdf output to find out whether the seller's registration number reached the page will not be
     * written, and the defects this document has had were both about what it FAILED to say.
     *
     * @return array<string, mixed>
     */
    public function data(CreditNote $note): array
    {
        $note->loadMissing(['items', 'tenant', 'invoice', 'asset']);

        // `$note->asset`, NOT `$note->lease?->unit?->asset` — that chain is the one migration
        // 2026_08_15_130000 replaced with a denormalized `asset_id`, *because it answers NULL* for a
        // note whose lease_id is null (a note against a unit-owner assessment, or any standalone
        // note). This service was still walking it, so exactly those notes printed with no property
        // name and an empty address block: the reader could not tell which mall issued the credit.
        $asset = $note->asset;

        return [
            'note' => $note,
            'tenant' => $note->tenant,
            'invoice' => $note->invoice,
            'asset' => $asset,
            'isRtl' => app()->getLocale() === 'ar',
            ...IssuingEntity::forView($asset),
            'vatSummary' => VatSummary::forItems($note->items),
        ];
    }

    public function filename(CreditNote $note): string
    {
        return $note->number.'.pdf';
    }
}
