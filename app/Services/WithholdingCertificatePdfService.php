<?php

namespace App\Services;

use App\Models\Vendor;
use App\Services\Reports\WithholdingTaxReturnService;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonImmutable;

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

    /**
     * The certificate as a PDF, in the language the SUPPLIER reads.
     *
     * This is the document the supplier hands to their own accountant to claim the tax already
     * withheld from them, so the language that matters is theirs, not the payer's clerk's.
     */
    public function build(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end, ?string $locale = null): string
    {
        return PdfDocument::make('vendors.withholding-certificate')
            ->locale(DocumentLocale::resolve($locale, $vendor))
            ->data(fn (): array => [
                'vendor' => $vendor,
                'certificate' => $this->returns->forVendor($vendor, $start, $end),
                'start' => $start,
                'end' => $end,
                // Filed per REGISTRATION, not per mall — the issuer is the operator, with no
                // property letterhead. Same rule the VAT return follows.
                ...IssuingEntity::forView(null),
            ])
            ->reference($vendor->name.' · '.$start->format('d/m/Y').' – '.$end->format('d/m/Y'))
            ->margins(['left' => 15, 'right' => 15])
            ->render();
    }

    public function filename(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return 'wht-certificate-'.$vendor->id.'-'.$start->format('Y-m-d').'-'.$end->format('Y-m-d').'.pdf';
    }
}
