@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.income_statement_title'))

@section('content')
    @php $locale = $meta['locale'] ?? app()->getLocale(); @endphp

    @php
        $lines = function ($rows, $total, $totalLabel) use ($locale) {
            $out = '<table class="report">';
            foreach ($rows as $row) {
                $out .= '<tr><td class="code" style="width:5rem">'.e($row['code']).'</td>'
                    .'<td>'.e($locale === 'ar' ? $row['name_ar'] : $row['name_en']).'</td>'
                    .'<td class="num">'.number_format($row['amount'], 2).'</td></tr>';
            }
            $out .= '<tr class="total-row"><td colspan="2">'.e($totalLabel).'</td>'
                .'<td class="num">EGP '.number_format($total, 2).'</td></tr>';

            return $out.'</table>';
        };
    @endphp

    <div class="section-title">{{ __('admin.reports.revenue') }}</div>
    {!! $lines($report['revenue'], $report['total_revenue'], __('admin.reports.total_revenue')) !!}

    <div class="section-title">{{ __('admin.reports.expenses') }}</div>
    {!! $lines($report['expense'], $report['total_expense'], __('admin.reports.total_expenses')) !!}

    <table class="report" style="margin-top:14px;">
        <tr class="grand">
            <td>{{ __('admin.reports.net_profit') }}</td>
            <td class="num {{ $report['net_profit'] >= 0 ? 'ok' : 'bad' }}">EGP {{ number_format($report['net_profit'], 2) }}</td>
        </tr>
    </table>
@endsection
