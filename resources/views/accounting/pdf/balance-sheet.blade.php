@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.balance_sheet_title'))

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

    <div class="section-title">{{ __('admin.reports.assets') }}</div>
    {!! $lines($report['assets'], $report['total_assets'], __('admin.reports.total_assets')) !!}

    <div class="section-title">{{ __('admin.reports.liabilities_equity') }}</div>
    {!! $lines($report['liabilities'], $report['total_liabilities'], __('admin.reports.total_liabilities')) !!}
    <div style="height:6px;"></div>
    {!! $lines($report['equity'], $report['total_equity'], __('admin.reports.total_equity')) !!}

    <table class="report" style="margin-top:6px;">
        <tr>
            <td>{{ __('admin.reports.net_income_period') }}</td>
            <td class="num">{{ number_format($report['net_income'], 2) }}</td>
        </tr>
        <tr class="grand">
            <td>{{ __('admin.reports.total_equity_and_liabilities') }}</td>
            <td class="num">EGP {{ number_format($report['total_equity_and_liabilities'], 2) }}</td>
        </tr>
    </table>

    <p style="margin-top:10px; font-weight:bold;" class="{{ $report['balanced'] ? 'ok' : 'bad' }}">
        {{ $report['balanced'] ? '✓ ' . __('admin.reports.balanced') : '✗ ' . __('admin.reports.not_balanced') }}
    </p>
@endsection
