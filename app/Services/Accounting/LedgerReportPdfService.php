<?php

namespace App\Services\Accounting;

use App\Support\IssuingEntity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders the financial statements (ميزان المراجعة / قائمة الدخل / قائمة المركز
 * المالي) to PDF for printing and sharing with owners + auditors. Bilingual +
 * RTL-aware, mirroring the mpdf setup used by the invoice / statement services.
 */
class LedgerReportPdfService
{
    public function __construct(private LedgerReportService $reports) {}

    public function trialBalance(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period): string
    {
        return $this->render('accounting.pdf.trial-balance', [
            'report' => $this->reports->trialBalance($assetIds, $from, $to),
            'meta' => $this->meta($property, $period),
        ], $assetIds);
    }

    public function incomeStatement(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period): string
    {
        return $this->render('accounting.pdf.income-statement', [
            'report' => $this->reports->incomeStatement($assetIds, $from, $to),
            'meta' => $this->meta($property, $period),
        ], $assetIds);
    }

    public function balanceSheet(?array $assetIds, CarbonInterface $asOf, string $property): string
    {
        return $this->render('accounting.pdf.balance-sheet', [
            'report' => $this->reports->balanceSheet($assetIds, $asOf),
            'meta' => $this->meta($property, $asOf->format('d/m/Y')),
        ], $assetIds);
    }

    public function cashFlow(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period): string
    {
        return $this->render('accounting.pdf.cash-flow', [
            'report' => $this->reports->cashFlow($assetIds, $from, $to),
            'meta' => $this->meta($property, $period),
        ], $assetIds);
    }

    public function filename(string $report, string $period): string
    {
        // `$period` is `2026` or `2026-03` — a monthly export must not land on disk under a name
        // that reads like the whole year's.
        return $report.'-'.$period.'-'.now()->format('Ymd').'.pdf';
    }

    /** @return array<string, string> */
    private function meta(string $property, string $period): array
    {
        return [
            'property' => $property,
            'period' => $period,
            'generated_on' => now()->format('d/m/Y H:i'),
            'locale' => app()->getLocale(),
        ];
    }

    /** @param  array<int>|null  $assetIds  the report's property scope; one mall means one letterhead */
    private function render(string $view, array $data, ?array $assetIds = null): string
    {
        $isRtl = app()->getLocale() === 'ar';
        // One seam for all four statements — they share `accounting.pdf.layout`, so the issuer is
        // stated here rather than in each of balanceSheet()/incomeStatement()/trialBalance()/
        // cashFlow().
        //
        // Scoped: a statement filtered to ONE mall carries that mall's logo; two or more, or none,
        // is a portfolio document and carries the operator's identity alone.
        $html = View::make($view, [...$data, ...IssuingEntity::forViewScopedTo($assetIds)])->render();

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
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
