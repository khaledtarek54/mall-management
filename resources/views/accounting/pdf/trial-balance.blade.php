@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.trial_balance_title'))

@section('content')
    @php $locale = $meta['locale'] ?? app()->getLocale(); @endphp
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('admin.tables.ledger_account.code') }}</th>
                <th>{{ __('admin.tables.ledger_account.account') }}</th>
                <th class="num">{{ __('admin.fields.debit') }}</th>
                <th class="num">{{ __('admin.fields.credit') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['rows'] as $row)
                <tr>
                    <td class="code">{{ $row['code'] }}</td>
                    <td>{{ $locale === 'ar' ? $row['name_ar'] : $row['name_en'] }}</td>
                    <td class="num">{{ $row['debit_balance'] > 0 ? number_format($row['debit_balance'], 2) : '—' }}</td>
                    <td class="num">{{ $row['credit_balance'] > 0 ? number_format($row['credit_balance'], 2) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="grand">
                <td colspan="2">{{ __('admin.reports.totals') }}</td>
                <td class="num">EGP {{ number_format($report['total_debit'], 2) }}</td>
                <td class="num">EGP {{ number_format($report['total_credit'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
    <p style="margin-top:10px; font-weight:bold;" class="{{ $report['balanced'] ? 'ok' : 'bad' }}">
        {{ \App\Support\StatementIntegrity::balance((bool) $report['balanced']) }}
    </p>
@endsection
