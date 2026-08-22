@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.cash_flow_title'))

@section('content')
    @php $locale = $meta['locale'] ?? app()->getLocale(); @endphp


    <div class="section-title">{{ __('admin.reports.operating_activities') }}</div>
    <table class="report">
        <tr><td colspan="2">{{ __('admin.reports.net_income') }}</td><td class="num">{{ number_format($report['net_income'], 2) }}</td></tr>
    </table>
    @include('accounting.pdf._statement-section', ['rows' => $report['adjustments'], 'total' => $report['operating_total'], 'totalLabel' => __('admin.reports.net_cash_operating'), 'locale' => $locale, 'grouped' => false])

    <div class="section-title">{{ __('admin.reports.investing_activities') }}</div>
    @include('accounting.pdf._statement-section', ['rows' => $report['investing'], 'total' => $report['investing_total'], 'totalLabel' => __('admin.reports.net_cash_investing'), 'locale' => $locale, 'grouped' => false])

    <div class="section-title">{{ __('admin.reports.financing_activities') }}</div>
    @include('accounting.pdf._statement-section', ['rows' => $report['financing'], 'total' => $report['financing_total'], 'totalLabel' => __('admin.reports.net_cash_financing'), 'locale' => $locale, 'grouped' => false])

    <table class="report" style="margin-top:14px;">
        <tr><td>{{ __('admin.reports.cash_at_start') }}</td><td class="num">EGP {{ number_format($report['cash_opening'], 2) }}</td></tr>
        <tr><td>{{ __('admin.reports.net_change_in_cash') }}</td><td class="num">EGP {{ number_format($report['net_change'], 2) }}</td></tr>
        <tr class="grand">
            <td>{{ __('admin.reports.cash_at_end') }}</td>
            <td class="num {{ $report['net_change'] >= 0 ? 'ok' : 'bad' }}">EGP {{ number_format($report['cash_closing'], 2) }}</td>
        </tr>
    </table>
@endsection
