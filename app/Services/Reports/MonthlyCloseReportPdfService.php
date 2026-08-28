<?php

namespace App\Services\Reports;

use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonImmutable;

class MonthlyCloseReportPdfService
{
    public function __construct(private ReportService $reports) {}

    /**
     * The monthly close pack, in whichever language the accountant asked for it.
     *
     * No recipient: this is an internal document generated on demand by the person about to read it,
     * so the request's own language is the right default. `SetTitle` is gone — mpdf reads the
     * template's own `<title>`, which is resolved inside the document's locale and therefore says
     * the same thing as the heading rather than whatever the operator's panel was set to.
     */
    public function build(CarbonImmutable $period, ?string $locale = null): string
    {
        return PdfDocument::make('reports.monthly-close')
            ->locale(DocumentLocale::resolve($locale))
            ->data(fn (): array => [
                'report' => $this->reports->monthlyClose($period),
                'period' => $period,
                'generatedAt' => CarbonImmutable::now(),
                // Deliberately portfolio: the monthly close is one document for the whole operator
                // and has no single property to brand it with. Registered as such in the
                // conformance gate.
                ...IssuingEntity::forView(),
            ])
            ->reference(__('admin.reports.monthly_close_title', ['period' => $period->format('Y-m')]))
            ->fontSize(10)
            ->render();
    }

    public function filename(CarbonImmutable $period): string
    {
        return 'atriom-monthly-close-'.$period->format('Y-m').'.pdf';
    }
}
