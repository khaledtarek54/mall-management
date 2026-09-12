@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.trial_balance_title'))

{{--
    Three column pairs — opening · movement · closing — each footing on its own (2026-09-11). Until
    then the printed statement carried the window's net movement under the heading "balance", and a
    month's trial balance is not a movement summary. Rendered LANDSCAPE by the service: six money
    columns on a portrait page are either clipped or shrunk past reading.

    Printed as the chart's TREE at the fold the screen was at (2026-09-12, point 19): `$nodes` is
    the visible list the service resolved — a summary account indented by its depth in bold with
    the sums of the leaves beneath it, a folded branch as its summary row alone. The totals are
    still the report's own, over the LEAVES, so a folded tree foots to the same figures as the
    open one.
--}}
@section('content')
    @php $locale = $meta['locale'] ?? app()->getLocale(); @endphp
    <table class="report">
        <thead>
            <tr>
                <th rowspan="2">{{ __('admin.tables.ledger_account.code') }}</th>
                <th rowspan="2">{{ __('admin.tables.ledger_account.account') }}</th>
                {{-- Centred over its pair: `.num` loses to `table.report th` on specificity, so a
                     colspan heading would otherwise sit at the left edge of two right-aligned columns. --}}
                <th colspan="2" style="text-align:center;">{{ __('admin.reports.trial_balance_columns.opening') }}</th>
                <th colspan="2" style="text-align:center;">{{ __('admin.reports.trial_balance_columns.movement') }}</th>
                <th colspan="2" style="text-align:center;">{{ __('admin.reports.trial_balance_columns.closing') }}</th>
            </tr>
            <tr>
                <th class="num">{{ __('admin.fields.debit') }}</th>
                <th class="num">{{ __('admin.fields.credit') }}</th>
                <th class="num">{{ __('admin.fields.debit') }}</th>
                <th class="num">{{ __('admin.fields.credit') }}</th>
                <th class="num">{{ __('admin.fields.debit') }}</th>
                <th class="num">{{ __('admin.fields.credit') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($nodes as $row)
                <tr{!! $row['has_children'] ? ' class="subtotal-row"' : '' !!}>
                    {{-- Indented in the READING direction — a fixed `padding-left` would push an
                         Arabic tree's children the wrong way. --}}
                    <td class="code" style="padding-{{ $locale === 'ar' ? 'right' : 'left' }}: {{ 6 + $row['depth'] * 12 }}px;">{{ $row['code'] }}</td>
                    <td>{{ $locale === 'ar' ? $row['name_ar'] : $row['name_en'] }}</td>
                    <td class="num">{{ ($row['opening_debit'] ?? 0) > 0 ? number_format($row['opening_debit'], 2) : '—' }}</td>
                    <td class="num">{{ ($row['opening_credit'] ?? 0) > 0 ? number_format($row['opening_credit'], 2) : '—' }}</td>
                    <td class="num">{{ ($row['debit_total'] ?? 0) > 0 ? number_format($row['debit_total'], 2) : '—' }}</td>
                    <td class="num">{{ ($row['credit_total'] ?? 0) > 0 ? number_format($row['credit_total'], 2) : '—' }}</td>
                    <td class="num">{{ $row['debit_balance'] > 0 ? number_format($row['debit_balance'], 2) : '—' }}</td>
                    <td class="num">{{ $row['credit_balance'] > 0 ? number_format($row['credit_balance'], 2) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="grand">
                {{-- The currency is stated once, on the totals label: the other three statements
                     print it on their totals, and a page of figures naming no currency does not
                     file. Not on every cell — six "EGP" prefixes wrap the row. --}}
                <td colspan="2">{{ __('admin.reports.totals') }} (EGP)</td>
                <td class="num">{{ number_format($report['total_opening_debit'] ?? 0, 2) }}</td>
                <td class="num">{{ number_format($report['total_opening_credit'] ?? 0, 2) }}</td>
                <td class="num">{{ number_format($report['total_movement_debit'] ?? 0, 2) }}</td>
                <td class="num">{{ number_format($report['total_movement_credit'] ?? 0, 2) }}</td>
                <td class="num">{{ number_format($report['total_debit'], 2) }}</td>
                <td class="num">{{ number_format($report['total_credit'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
    <p style="margin-top:10px; font-weight:bold;" class="{{ $report['balanced'] ? 'ok' : 'bad' }}">
        {{ \App\Support\StatementIntegrity::balance((bool) $report['balanced']) }}
    </p>
@endsection
