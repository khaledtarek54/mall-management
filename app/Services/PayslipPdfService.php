<?php

namespace App\Services;

use App\Models\PayrollLine;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;

/**
 * Renders a bilingual (LTR/RTL) payslip PDF for one employee's payroll line
 * (module 24, Phase 3). Mirrors InvoicePdfService's mpdf setup.
 */
class PayslipPdfService
{
    /**
     * The payslip as a PDF, in the language the EMPLOYEE reads.
     *
     * The recipient here is a person, not a company, and an employee who reads only Arabic being
     * handed an English breakdown of their own deductions is the plainest case this change exists
     * for. `employees.locale` is that answer (added 2026-08-28); null is the normal state and falls
     * through to whoever is generating the run.
     */
    public function build(PayrollLine $line, ?string $locale = null): string
    {
        return $this->document($line, $locale)->render();
    }

    /**
     * The configured document, before mpdf touches it — the seam a test reads the HTML from, as on
     * the four money documents. See {@see InvoicePdfService::document()}.
     */
    public function document(PayrollLine $line, ?string $locale = null): PdfDocument
    {
        $line->loadMissing(['payroll.asset', 'employee.department']);

        return PdfDocument::make('payslips.pdf')
            ->locale(DocumentLocale::resolve($locale, $line->employee))
            ->data(fn (): array => [
                'line' => $line,
                'payroll' => $line->payroll,
                'employee' => $line->employee,
                'asset' => $line->payroll?->asset,
                // A payslip is issued by the EMPLOYER, so the header names the registered entity
                // and the property the employee is posted to stays in the sub-line. Passing the
                // payroll's property is what puts that mall's logo on it.
                ...IssuingEntity::forView($line->payroll?->asset),
            ])
            ->reference($line->payroll?->number)
            ->bleed();
    }

    public function filename(PayrollLine $line): string
    {
        $code = $line->employee?->code ?: $line->employee_id;

        return 'payslip-'.($line->payroll?->number ?? 'run').'-'.$code.'.pdf';
    }
}
