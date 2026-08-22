@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.income_statement_title'))

@section('content')
    @php $locale = $meta['locale'] ?? app()->getLocale(); @endphp


    <div class="section-title">{{ __('admin.reports.revenue') }}</div>
    @include('accounting.pdf._statement-section', ['rows' => $report['revenue'], 'total' => $report['total_revenue'], 'totalLabel' => __('admin.reports.total_revenue'), 'locale' => $locale])

    <div class="section-title">{{ __('admin.reports.expenses') }}</div>
    @include('accounting.pdf._statement-section', ['rows' => $report['expense'], 'total' => $report['total_expense'], 'totalLabel' => __('admin.reports.total_expenses'), 'locale' => $locale])

    <table class="report" style="margin-top:14px;">
        <tr class="grand">
            <td>{{ __('admin.reports.net_profit') }}</td>
            <td class="num {{ $report['net_profit'] >= 0 ? 'ok' : 'bad' }}">EGP {{ number_format($report['net_profit'], 2) }}</td>
        </tr>
    </table>
@endsection
