<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class MonthlyCloseReportPdfService
{
    public function __construct(private ReportService $reports) {}

    public function build(CarbonImmutable $period): string
    {
        $report = $this->reports->monthlyClose($period);
        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('reports.monthly-close', [
            'report' => $report,
            'period' => $period,
            'isRtl' => $isRtl,
            'generatedAt' => CarbonImmutable::now(),
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
            'default_font_size' => 10,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'useSubstitutions' => true,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetDirectionality($isRtl ? 'rtl' : 'ltr');
        $mpdf->SetTitle(__('admin.reports.monthly_close_title', ['period' => $period->format('Y-m')]));
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function filename(CarbonImmutable $period): string
    {
        return 'atriom-monthly-close-'.$period->format('Y-m').'.pdf';
    }
}
