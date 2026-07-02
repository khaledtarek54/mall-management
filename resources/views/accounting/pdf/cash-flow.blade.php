@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.cash_flow_title'))

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

    <div class="section-title">{{ __('admin.reports.operating_activities') }}</div>
    <table class="report">
        <tr><td colspan="2">{{ __('admin.reports.net_income') }}</td><td class="num">{{ number_format($report['net_income'], 2) }}</td></tr>
    </table>
    {!! $lines($report['adjustments'], $report['operating_total'], __('admin.reports.net_cash_operating')) !!}

    <div class="section-title">{{ __('admin.reports.investing_activities') }}</div>
    {!! $lines($report['investing'], $report['investing_total'], __('admin.reports.net_cash_investing')) !!}

    <div class="section-title">{{ __('admin.reports.financing_activities') }}</div>
    {!! $lines($report['financing'], $report['financing_total'], __('admin.reports.net_cash_financing')) !!}

    <table class="report" style="margin-top:14px;">
        <tr><td>{{ __('admin.reports.cash_at_start') }}</td><td class="num">EGP {{ number_format($report['cash_opening'], 2) }}</td></tr>
        <tr><td>{{ __('admin.reports.net_change_in_cash') }}</td><td class="num">EGP {{ number_format($report['net_change'], 2) }}</td></tr>
        <tr class="grand">
            <td>{{ __('admin.reports.cash_at_end') }}</td>
            <td class="num {{ $report['net_change'] >= 0 ? 'ok' : 'bad' }}">EGP {{ number_format($report['cash_closing'], 2) }}</td>
        </tr>
    </table>
@endsection
