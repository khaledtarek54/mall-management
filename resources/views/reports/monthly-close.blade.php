@php $isRtl = $isRtl ?? false; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.reports.monthly_close_title', ['period' => $report['period']]) }}</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body {
            color: #0F1419;
            font-size: 10pt;
            line-height: 1.45;
            margin: 0;
        }
        .header {
            border-bottom: 2px solid #0F766E;
            padding-bottom: 14px;
            margin-bottom: 22px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name { font-size: 20pt; font-weight: bold; color: #0F1419; }
        .brand-sub { color: #8C8478; font-size: 8.5pt; }
        .doc-title {
            font-size: 15pt;
            color: #0F766E;
            text-align: {{ $isRtl ? 'left' : 'right' }};
            letter-spacing: {{ $isRtl ? '0' : '3px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
        }
        .doc-meta {
            text-align: {{ $isRtl ? 'left' : 'right' }};
            font-size: 8.5pt;
            color: #6B6660;
            margin-top: 4px;
        }
        .section {
            margin-bottom: 18px;
        }
        .section h2 {
            font-size: 11pt;
            font-weight: bold;
            color: #0F1419;
            margin: 0 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #E5E7EB;
            letter-spacing: {{ $isRtl ? '0' : '0.5px' }};
        }
        .kpi-grid { width: 100%; border-collapse: collapse; }
        .kpi-grid td {
            width: 25%;
            vertical-align: top;
            padding: 10px 12px;
            background: #FAFAFA;
            border: 1px solid #E5E7EB;
        }
        .kpi-label {
            font-size: 8pt;
            color: #6B6660;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '0.5px' }};
        }
        .kpi-value {
            font-size: 14pt;
            font-weight: bold;
            color: #0F1419;
            margin-top: 4px;
        }
        .kpi-sub {
            font-size: 8pt;
            color: #6B6660;
            margin-top: 2px;
        }
        table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data th, table.data td {
            padding: 6px 8px;
            border-bottom: 1px solid #F0EEEC;
            font-size: 9.5pt;
            text-align: {{ $isRtl ? 'right' : 'left' }};
        }
        table.data th {
            background: #F8F8F7;
            font-weight: bold;
            color: #5B564F;
            font-size: 8.5pt;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '0.5px' }};
        }
        table.data .num {
            text-align: {{ $isRtl ? 'left' : 'right' }};
            font-variant-numeric: tabular-nums;
        }
        .footer {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #E5E7EB;
            font-size: 8pt;
            color: #8C8478;
        }
    </style>
</head>
<body>

<div class="header">
    <table>
        <tr>
            <td style="width: 50%;">
                @include('partials.issuer-logo')
                <div class="brand-name">{{ $issuerName }}</div>
                <div class="brand-sub">{{ __('admin.reports.brand_sub') }}</div>
            </td>
            <td style="width: 50%;">
                <div class="doc-title">{{ __('admin.reports.monthly_close') }}</div>
                <div class="doc-meta">
                    {{ $report['period_label'] }}<br>
                    {{ __('admin.reports.generated_at') }}: {{ $generatedAt->format('d/m/Y H:i') }}
                </div>
            </td>
        </tr>
    </table>
</div>

{{-- KPI GRID --}}
<div class="section">
    <table class="kpi-grid">
        <tr>
            <td>
                <div class="kpi-label">{{ __('admin.reports.invoices_issued') }}</div>
                <div class="kpi-value">{{ number_format($report['invoices']['count']) }}</div>
                <div class="kpi-sub">EGP {{ number_format($report['invoices']['total'], 2) }}</div>
            </td>
            <td>
                <div class="kpi-label">{{ __('admin.reports.payments_captured') }}</div>
                <div class="kpi-value">{{ number_format($report['payments']['count']) }}</div>
                <div class="kpi-sub">EGP {{ number_format($report['payments']['total'], 2) }}</div>
            </td>
            <td>
                <div class="kpi-label">{{ __('admin.reports.collections_rate') }}</div>
                <div class="kpi-value">{{ number_format($report['collections_rate'], 1) }}%</div>
                <div class="kpi-sub">{{ __('admin.reports.of_invoiced') }}</div>
            </td>
            <td>
                <div class="kpi-label">{{ __('admin.reports.outstanding_ar') }}</div>
                <div class="kpi-value">EGP {{ number_format($report['outstanding_total'], 2) }}</div>
                <div class="kpi-sub">{{ __('admin.reports.as_of_close') }}</div>
            </td>
        </tr>
    </table>
</div>

{{-- INVOICES BY STATUS --}}
<div class="section">
    <h2>{{ __('admin.reports.invoices_by_status') }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('admin.tables.common.status') }}</th>
                <th class="num">{{ __('admin.tables.invoice.number') }}</th>
                <th class="num">{{ __('admin.tables.invoice.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['invoices']['by_status'] as $status => $row)
                <tr>
                    <td>{{ __("admin.statuses.invoice.{$status}") }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="num">{{ number_format($row['total'], 2) }}</td>
                </tr>
            @endforeach
            <tr style="font-weight: bold; background: #F8F8F7;">
                <td>{{ __('admin.reports.total') }}</td>
                <td class="num">{{ number_format($report['invoices']['count']) }}</td>
                <td class="num">{{ number_format($report['invoices']['total'], 2) }}</td>
            </tr>
        </tbody>
    </table>
</div>

{{-- PAYMENTS BY METHOD --}}
@if(!empty($report['payments']['by_method']))
<div class="section">
    <h2>{{ __('admin.reports.payments_by_method') }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('admin.tables.payment.method') }}</th>
                <th class="num">{{ __('admin.tables.payment.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['payments']['by_method'] as $method => $total)
                <tr>
                    <td>{{ \App\Models\PaymentMethod::labelFor($method) }}</td>
                    <td class="num">{{ number_format($total, 2) }}</td>
                </tr>
            @endforeach
            <tr style="font-weight: bold; background: #F8F8F7;">
                <td>{{ __('admin.reports.total') }}</td>
                <td class="num">{{ number_format($report['payments']['total'], 2) }}</td>
            </tr>
        </tbody>
    </table>
</div>
@endif

{{-- AR AGING --}}
<div class="section">
    {{-- Name the day the buckets were aged at: for a month already closed that is
         month-end, for the month in progress it is today. A bucket like "31–60 days"
         is meaningless without it, and this is what the on-screen drill-down uses. --}}
    <h2>{{ __('admin.reports.ar_aging') }}
        <span style="font-weight: normal; font-size: 9pt;">
            — {{ __('admin.reports.aged_as_of') }} {{ \Illuminate\Support\Carbon::parse($report['ar_aging_as_of'])->format('d/m/Y') }}
        </span>
    </h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('admin.reports.bucket') }}</th>
                <th class="num">{{ __('admin.reports.invoice_count') }}</th>
                <th class="num">{{ __('admin.tables.invoice.balance') }}</th>
            </tr>
        </thead>
        <tbody>
            @php
                $bucketLabels = [
                    'current' => __('admin.widgets.ar_aging.current'),
                    'd_1_30' => __('admin.widgets.ar_aging.d_1_30'),
                    'd_31_60' => __('admin.widgets.ar_aging.d_31_60'),
                    'd_61_90' => __('admin.widgets.ar_aging.d_61_90'),
                    'd_90_plus' => __('admin.widgets.ar_aging.d_90_plus'),
                ];
            @endphp
            @foreach($report['ar_aging'] as $key => $row)
                <tr>
                    <td>{{ $bucketLabels[$key] ?? $key }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="num">{{ number_format($row['total'], 2) }}</td>
                </tr>
            @endforeach
            <tr style="font-weight: bold; background: #F8F8F7;">
                <td>{{ __('admin.reports.total') }}</td>
                <td class="num"></td>
                <td class="num">{{ number_format($report['outstanding_total'], 2) }}</td>
            </tr>
        </tbody>
    </table>
</div>

{{-- VAT --}}
<div class="section">
    <h2>{{ __('admin.reports.vat_summary') }}</h2>
    <table class="data">
        <tbody>
            <tr>
                <td>{{ __('admin.reports.vat_collected') }}</td>
                <td class="num">{{ number_format($report['invoices']['vat'], 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('admin.reports.taxable_revenue') }}</td>
                <td class="num">{{ number_format($report['invoices']['total'] - $report['invoices']['vat'], 2) }}</td>
            </tr>
        </tbody>
    </table>
</div>

{{-- REVENUE BY TYPE --}}
@if(!empty($report['revenue_by_type']))
<div class="section">
    <h2>{{ __('admin.reports.revenue_by_type') }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('admin.fields.type') }}</th>
                <th class="num">{{ __('admin.fields.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['revenue_by_type'] as $type => $amount)
                <tr>
                    <td>{{ __("admin.enums.invoice_item_type.{$type}") }}</td>
                    <td class="num">{{ number_format($amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- CREDIT NOTES --}}
@if($report['credit_notes']['count'] > 0)
<div class="section">
    <h2>{{ __('admin.reports.credit_notes_issued') }}</h2>
    <table class="data">
        <tbody>
            <tr>
                <td>{{ __('admin.reports.notes_issued') }}</td>
                <td class="num">{{ number_format($report['credit_notes']['count']) }}</td>
            </tr>
            <tr>
                <td>{{ __('admin.reports.total_issued') }}</td>
                <td class="num">{{ number_format($report['credit_notes']['total_issued'], 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('admin.reports.total_applied') }}</td>
                <td class="num">{{ number_format($report['credit_notes']['total_applied'], 2) }}</td>
            </tr>
        </tbody>
    </table>
</div>
@endif

<div class="footer">
    {{ __('admin.reports.footer') }}
</div>

</body>
</html>
