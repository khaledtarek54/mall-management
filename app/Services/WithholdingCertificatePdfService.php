<?php

namespace App\Services;

use App\Models\Vendor;
use App\Services\Reports\WithholdingTaxReturnService;
use App\Support\IssuingEntity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * شهادة خصم وإضافة — the certificate a supplier needs to reclaim what was withheld from them.
 *
 * Withholding under Law 91/2005 art. 59 is an ADVANCE PAYMENT of the supplier's own corporate income
 * tax, not a cost. Which means the supplier is entitled to set it against their tax bill — and they
 * cannot, without a document from the party that withheld it stating who withheld what, on what base,
 * over which period. Until this existed, switching `wht_enabled` on would have started deducting
 * money from suppliers with no way to evidence it, and the first vendor to ask for their certificate
 * would have got a spreadsheet.
 *
 * Mirrors {@see PayslipPdfService}'s mpdf setup, because the two documents have the same shape: one
 * counterparty, one period, issued BY the operator, read in either language.
 *
 * **The issuer is the registered entity, not the mall.** A certificate is evidence for the tax
 * authority about a withholding made under one tax registration, so `IssuingEntity::forView(null)`
 * is deliberate — passing a property would put a mall's logo on a document that is not the mall's
 * to issue, and the supplier may have been paid from several.
 */
class WithholdingCertificatePdfService
{
    public function __construct(private WithholdingTaxReturnService $returns) {}

    public function build(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $certificate = $this->returns->forVendor($vendor, $start, $end);

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('vendors.withholding-certificate', [
            'vendor' => $vendor,
            'certificate' => $certificate,
            'start' => $start,
            'end' => $end,
            ...IssuingEntity::forView(null),
        ])->render();

        $tempDir = storage_path('app/mpdf');

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 16,
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

    public function filename(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return 'wht-certificate-'.$vendor->id.'-'.$start->format('Y-m-d').'-'.$end->format('Y-m-d').'.pdf';
    }
}
