{{--
    The payslip (قسيمة راتب) — the one document in this set addressed to a PERSON rather than a
    company, and the one where the language mattering is least arguable: an employee who reads only
    Arabic being handed an English breakdown of their own deductions.

    Set in Direction D on the shared shell. The issuer here is the EMPLOYER — the registered entity
    leads and the property the employee is posted to stays in the caption beneath it, which is the
    opposite of a tenant-facing document where the mall leads.
--}}
@php
    use App\Support\Pdf\Bidi;

    $money = fn ($v) => number_format((float) $v, 2).' '.__('admin.payslip.egp');
@endphp

@extends('pdf.layout', [
    'title' => __('admin.payslip.title').' — '.$employee?->name,
    'issuerCaption' => $asset?->name ?? __('admin.fields.property_consolidated'),
])

@section('document')
    <div class="doc-type">{{ __('admin.payslip.title') }}</div>
    <div class="doc-number">{{ Bidi::isolate($payroll?->number ?? '') }}</div>
    <div class="doc-meta" style="margin-top:4pt;">
        {{ __('admin.payslip.month') }} <strong>{{ optional($payroll?->period_month)->format('m/Y') }}</strong>
    </div>
@endsection

@section('content')
    <table class="facts gap-l">
        <tr>
            <td>
                <div class="label">{{ __('admin.payslip.employee') }}</div>
                <div class="headline">{{ Bidi::isolate($employee?->name) }}</div>
                <div class="value">{{ __('admin.employees.fields.code') }}: {{ Bidi::isolate($employee?->code) }}</div>
                @if ($employee?->position)
                    <div class="value">{{ Bidi::isolate($employee->position) }}</div>
                @endif
                @if ($employee?->department)
                    <div class="value">{{ Bidi::isolate($employee->department->name) }}</div>
                @endif
            </td>
            <td class="last">
                <div class="label">{{ __('admin.payslip.details') }}</div>
                <div class="value">{{ __('admin.employees.fields.hire_date') }}: {{ optional($employee?->hire_date)->format('d/m/Y') }}</div>
                <div class="value">{{ __('admin.employees.fields.payment_method') }}: {{ __('admin.employees.methods.'.($employee?->payment_method ?? 'bank')) }}</div>
            </td>
        </tr>
    </table>

    <table class="totals" style="width:60%;">
        @if ((float) $line->allowances > 0)
            <tr>
                <td class="k">{{ __('admin.payslip.basic') }}</td>
                <td class="v">{{ $money($line->basic) }}</td>
            </tr>
            <tr>
                <td class="k">{{ __('admin.payslip.allowances') }}</td>
                <td class="v">{{ $money($line->allowances) }}</td>
            </tr>
        @endif
        <tr>
            <td class="k">{{ __('admin.payslip.gross') }}</td>
            <td class="v">{{ $money($line->gross) }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('admin.payslip.salary_tax') }}</td>
            <td class="v">− {{ $money($line->salary_tax) }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('admin.payslip.social_insurance') }}</td>
            <td class="v">− {{ $money($line->social_insurance) }}</td>
        </tr>
        @if ((float) $line->advance_deduction > 0)
            <tr>
                <td class="k">{{ __('admin.payslip.advance_deduction') }}</td>
                <td class="v">− {{ $money($line->advance_deduction) }}</td>
            </tr>
        @endif
        @if ((float) $line->other_deductions > 0)
            <tr>
                <td class="k">{{ __('admin.payslip.other_deductions') }}{{ $line->deduction_note ? ' — '.$line->deduction_note : '' }}</td>
                <td class="v">− {{ $money($line->other_deductions) }}</td>
            </tr>
        @endif
    </table>

    {{-- The take-home figure gets the panel: it is the one number an employee opens this to read,
         and burying it as the last row of a ledger makes them add up the page to find it. --}}
    <table class="balance" style="width:60%;">
        <tr>
            <td>
                <div class="label">{{ __('admin.payslip.net') }}</div>
            </td>
            <td class="figure">{{ $money($line->net) }}</td>
        </tr>
    </table>

    @if ((float) $line->employer_social_insurance > 0)
        <div class="panel" style="margin-top:14pt;">
            {{ __('admin.payslip.employer_social_insurance') }}: {{ $money($line->employer_social_insurance) }}
            <span class="muted">— {{ __('admin.payslip.employer_social_insurance_note') }}</span>
        </div>
    @endif

@endsection

@section('closing')
    {{ __('admin.payslip.footer') }}
@endsection
