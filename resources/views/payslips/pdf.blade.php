@php
    $isRtl = app()->getLocale() === 'ar';
    $money = fn ($v) => number_format((float) $v, 2).' '.__('admin.payslip.egp');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.payslip.title') }} — {{ $employee?->name }}</title>
    <style>
        /* No @page rule: page geometry (size, margins, the band the running footer sits in)
           belongs to App\Support\Pdf\PdfDocument. A template that set its own margins here
           silently overrode mpdf's and left no room beneath the body, so the running footer
           carrying the document reference and `page x of y` rendered nowhere at all. */
        * { box-sizing: border-box; }
        body { color: #0F1419; font-size: 10.5pt; line-height: 1.55; margin: 0; }
        .header { border-bottom: 2px solid #0F766E; padding-bottom: 14px; margin-bottom: 20px; }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name { font-size: 20pt; font-weight: bold; color: #0F1419; }
        .brand-sub { color: #8C8478; font-size: 9pt; }
        .doc-title { font-size: 16pt; color: #0F766E; text-align: {{ $isRtl ? 'left' : 'right' }}; }
        .doc-meta { text-align: {{ $isRtl ? 'left' : 'right' }}; font-size: 9pt; color: #6B6660; margin-top: 4px; }
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .parties td { width: 50%; vertical-align: top; padding: 0; }
        .label { font-size: 8pt; color: #8C8478; margin-bottom: 4px; }
        .party-name { font-weight: bold; font-size: 11pt; margin-bottom: 2px; }
        .party-line { color: #4A4A4A; font-size: 9.5pt; }
        table.amounts { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.amounts td { padding: 8px 10px; border-bottom: 1px solid #E7E1D6; }
        table.amounts td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; font-weight: bold; }
        .earn { color: #0F766E; }
        .ded { color: #B4341C; }
        .net-row td { border-top: 2px solid #0F766E; border-bottom: none; font-size: 12pt; font-weight: bold; padding-top: 12px; }
        .employer-note { margin-top: 14px; padding: 8px 10px; background: #F5F2EC; border-radius: 4px; font-size: 9pt; color: #4A4A4A; }
        .employer-note-sub { color: #8C8478; }
        .footer { margin-top: 28px; font-size: 8pt; color: #8C8478; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td>
                    {{-- The EMPLOYER. A payslip is issued by the company that pays the salary, so
                         the registered entity leads and the property the employee is posted to
                         stays underneath it. --}}
                    @include('partials.issuer-logo')
                    <div class="brand-name">{{ $issuerName }}</div>
                    <div class="brand-sub">{{ $asset?->name ?? __('admin.fields.property_consolidated') }}</div>
                </td>
                <td>
                    <div class="doc-title">{{ __('admin.payslip.title') }}</div>
                    <div class="doc-meta">
                        <strong>{{ $payroll?->number }}</strong><br>
                        {{ __('admin.payslip.month') }}: {{ optional($payroll?->period_month)->format('m/Y') }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="label">{{ __('admin.payslip.employee') }}</div>
                <div class="party-name">{{ $employee?->name }}</div>
                <div class="party-line">{{ __('admin.employees.fields.code') }}: {{ $employee?->code }}</div>
                @if ($employee?->position)
                    <div class="party-line">{{ $employee->position }}</div>
                @endif
                @if ($employee?->department)
                    <div class="party-line">{{ $employee->department->name }}</div>
                @endif
            </td>
            <td>
                <div class="label">{{ __('admin.payslip.details') }}</div>
                <div class="party-line">{{ __('admin.employees.fields.hire_date') }}: {{ optional($employee?->hire_date)->format('d/m/Y') }}</div>
                <div class="party-line">{{ __('admin.employees.fields.payment_method') }}: {{ __('admin.employees.methods.'.($employee?->payment_method ?? 'bank')) }}</div>
            </td>
        </tr>
    </table>

    <table class="amounts">
        @if ((float) $line->allowances > 0)
            <tr>
                <td class="earn">{{ __('admin.payslip.basic') }}</td>
                <td class="num earn">{{ $money($line->basic) }}</td>
            </tr>
            <tr>
                <td class="earn">{{ __('admin.payslip.allowances') }}</td>
                <td class="num earn">{{ $money($line->allowances) }}</td>
            </tr>
        @endif
        <tr>
            <td class="earn">{{ __('admin.payslip.gross') }}</td>
            <td class="num earn">{{ $money($line->gross) }}</td>
        </tr>
        <tr>
            <td class="ded">{{ __('admin.payslip.salary_tax') }}</td>
            <td class="num ded">− {{ $money($line->salary_tax) }}</td>
        </tr>
        <tr>
            <td class="ded">{{ __('admin.payslip.social_insurance') }}</td>
            <td class="num ded">− {{ $money($line->social_insurance) }}</td>
        </tr>
        @if ((float) $line->advance_deduction > 0)
            <tr>
                <td class="ded">{{ __('admin.payslip.advance_deduction') }}</td>
                <td class="num ded">− {{ $money($line->advance_deduction) }}</td>
            </tr>
        @endif
        @if ((float) $line->other_deductions > 0)
            <tr>
                <td class="ded">{{ __('admin.payslip.other_deductions') }}{{ $line->deduction_note ? ' — '.$line->deduction_note : '' }}</td>
                <td class="num ded">− {{ $money($line->other_deductions) }}</td>
            </tr>
        @endif
        <tr class="net-row">
            <td>{{ __('admin.payslip.net') }}</td>
            <td class="num">{{ $money($line->net) }}</td>
        </tr>
    </table>

    @if ((float) $line->employer_social_insurance > 0)
        <div class="employer-note">
            {{ __('admin.payslip.employer_social_insurance') }}: {{ $money($line->employer_social_insurance) }}
            <span class="employer-note-sub">— {{ __('admin.payslip.employer_social_insurance_note') }}</span>
        </div>
    @endif

    <div class="footer">{{ __('admin.payslip.footer') }}</div>
</body>
</html>
