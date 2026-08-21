<?php

namespace App\Services;

use App\Models\PayrollLine;
use App\Support\IssuingEntity;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a bilingual (LTR/RTL) payslip PDF for one employee's payroll line
 * (module 24, Phase 3). Mirrors InvoicePdfService's mpdf setup.
 */
class PayslipPdfService
{
    public function build(PayrollLine $line): string
    {
        $line->loadMissing(['payroll.asset', 'employee.department']);

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('payslips.pdf', [
            'line' => $line,
            'payroll' => $line->payroll,
            'employee' => $line->employee,
            'asset' => $line->payroll?->asset,
            // No asset: a payslip is issued by the EMPLOYER, so the header names the registered
            // entity and the property the employee is posted to stays in the sub-line.
            // The payroll's property — it is on the line above as `$asset`, and passing it is
            // what puts that mall's logo on the payslip.
            ...IssuingEntity::forView($line->payroll?->asset),
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

    public function filename(PayrollLine $line): string
    {
        $code = $line->employee?->code ?: $line->employee_id;

        return 'payslip-'.($line->payroll?->number ?? 'run').'-'.$code.'.pdf';
    }
}
