@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.balance_sheet_title'))

@section('content')
    @php $locale = $meta['locale'] ?? app()->getLocale(); @endphp


    <div class="section-title">{{ __('admin.reports.assets') }}</div>
    @include('accounting.pdf._statement-section', ['rows' => $report['assets'], 'total' => $report['total_assets'], 'totalLabel' => __('admin.reports.total_assets'), 'locale' => $locale])

    <div class="section-title">{{ __('admin.reports.liabilities_equity') }}</div>
    @include('accounting.pdf._statement-section', ['rows' => $report['liabilities'], 'total' => $report['total_liabilities'], 'totalLabel' => __('admin.reports.total_liabilities'), 'locale' => $locale])
    <div style="height:6px;"></div>
    @include('accounting.pdf._statement-section', ['rows' => $report['equity'], 'total' => $report['total_equity'], 'totalLabel' => __('admin.reports.total_equity'), 'locale' => $locale])

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
