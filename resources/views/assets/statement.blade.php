@php $isRtl = app()->getLocale() === 'ar'; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.statement.property_title') }} {{ $asset->name }}</title>
    <style>
        /* No @page rule: page geometry (size, margins, the band the running footer sits in)
           belongs to App\Support\Pdf\PdfDocument. A template that set its own margins here
           silently overrode mpdf's and left no room beneath the body, so the running footer
           carrying the document reference and `page x of y` rendered nowhere at all. */
        * { box-sizing: border-box; }
        body { color: #14213D; font-size: 10pt; line-height: 1.5; margin: 0; }

        .header { border-bottom: 2px solid #14213D; padding-bottom: 14px; margin-bottom: 20px; }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name { font-size: 20pt; font-weight: bold; color: #14213D; letter-spacing: {{ $isRtl ? '0' : '0.5px' }}; }
        .brand-sub { color: #7D8595; font-size: 9pt; }
        .doc-title {
            font-size: 16pt; color: #14213D;
            text-align: {{ $isRtl ? 'left' : 'right' }};
            letter-spacing: {{ $isRtl ? '0' : '3px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
        }
        .doc-meta { text-align: {{ $isRtl ? 'left' : 'right' }}; font-size: 9pt; color: #7D8595; margin-top: 4px; }
        .doc-meta strong { color: #14213D; }

        .label {
            font-size: 8pt; color: #7D8595;
            letter-spacing: {{ $isRtl ? '0' : '1.5px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            margin-bottom: 4px;
        }
        .party-name { font-weight: bold; font-size: 11pt; margin-bottom: 2px; }
        .party-line { color: #4A5468; font-size: 9.5pt; }

        .summary {
            width: 100%; border-collapse: collapse; margin-bottom: 20px;
            background: #F2F4F8;
            border-left: {{ $isRtl ? '0 none' : '3px solid #14213D' }};
            border-right: {{ $isRtl ? '3px solid #14213D' : '0 none' }};
        }
        .summary td {
            padding: 10px 14px; width: 25%;
            border-left: {{ $isRtl ? '1px solid rgba(201,169,97,0.2)' : '0 none' }};
            border-right: {{ $isRtl ? '0 none' : '1px solid rgba(201,169,97,0.2)' }};
        }
        .summary td:last-child { border-left: 0 none; border-right: 0 none; }
        .summary .stat-label {
            font-size: 8pt; color: #7D8595;
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
        }
        .summary .stat-value { font-size: 14pt; font-weight: bold; color: #14213D; margin-top: 4px; }
        .summary .stat-value.warn { color: #B4462C; }
        .summary .stat-value.good { color: #2E6B4F; }

        .section-title {
            font-size: 11pt; font-weight: bold; color: #14213D;
            margin: 18px 0 8px; padding-bottom: 4px;
            border-bottom: 1px solid #14213D;
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
        }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data thead th {
            background: #14213D; color: #F2F4F8;
            text-align: {{ $isRtl ? 'right' : 'left' }};
            padding: 8px 10px; font-size: 8.5pt;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
            font-weight: normal;
        }
        table.data thead th.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        table.data tbody td { padding: 8px 10px; border-bottom: 1px solid #EBEEF3; vertical-align: top; font-size: 9.5pt; }
        table.data tbody td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        table.data tbody td.muted { color: #7D8595; }
        table.data tfoot td { padding: 8px 10px; font-weight: bold; border-top: 2px solid #14213D; background: #F2F4F8; }
        table.data tfoot td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }

        .status-pill {
            display: inline-block; padding: 2px 8px; border-radius: 8px;
            font-size: 7.5pt;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '0.5px' }};
        }
        .status-paid { background: #DCEDE4; color: #2E6B4F; }
        .status-issued { background: #DDE4F0; color: #14213D; }
        .status-partially_paid { background: #FBF4E7; color: #8A6212; }
        .status-overdue { background: #F7DFD8; color: #B4462C; }

        .empty { color: #7D8595; font-size: 9.5pt; text-align: center; padding: 14px; font-style: italic; }
        .footer { border-top: 1px solid #EBEEF3; padding-top: 8px; margin-top: 18px; font-size: 8pt; color: #7D8595; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width:60%;">
                    @include('partials.issuer-logo')
                    <div class="brand-name">{{ $issuerName }}</div>
                    <div class="brand-sub">
                        @if($asset->code)<strong>{{ $asset->code }}</strong> · @endif
                        @if($asset->address){{ $asset->address }}@endif
                        @if($asset->city), {{ $asset->city }}@endif
                    </div>
                </td>
                <td style="width:40%;">
                    <div class="doc-title">{{ __('admin.statement.property_title') }}</div>
                    <div class="doc-meta">
                        <div>{{ __('admin.statement.as_of') }}: <strong>{{ $asOf->format('d/m/Y') }}</strong></div>
                        <div>{{ __('admin.statement.period_label') }}: {{ $since->format('d/m/Y') }} – {{ $asOf->format('d/m/Y') }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="summary">
        <tr>
            <td>
                <div class="stat-label">{{ __('admin.statement.outstanding') }}</div>
                <div class="stat-value {{ $summary['outstanding'] > 0 ? 'warn' : '' }}">EGP {{ number_format($summary['outstanding'], 2) }}</div>
            </td>
            <td>
                <div class="stat-label">{{ __('admin.statement.overdue') }}</div>
                <div class="stat-value {{ $summary['overdue'] > 0 ? 'warn' : '' }}">EGP {{ number_format($summary['overdue'], 2) }}</div>
            </td>
            <td>
                <div class="stat-label">{{ __('admin.statement.total_billed') }}</div>
                <div class="stat-value">EGP {{ number_format($summary['total_billed'], 2) }}</div>
            </td>
            <td>
                <div class="stat-label">{{ __('admin.statement.total_paid') }}</div>
                <div class="stat-value good">EGP {{ number_format($summary['total_paid'], 2) }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="stat-label">{{ __('admin.statement.occupancy') }}</div>
                <div class="stat-value">
                    {{ $summary['units_occupied'] }} / {{ $summary['units_total'] }}
                    @if($summary['units_total'] > 0)
                        <span style="font-size:10pt;color:#7D8595;font-weight:normal;">
                            ({{ number_format(($summary['units_occupied'] / $summary['units_total']) * 100, 0) }}%)
                        </span>
                    @endif
                </div>
            </td>
            <td colspan="2">
                <div class="stat-label">{{ __('admin.statement.open_invoices') }}</div>
                <div class="stat-value">{{ $summary['open_count'] }}</div>
            </td>
        </tr>
    </table>

    @if($delinquentTenants->isNotEmpty())
        <div class="section-title">{{ __('admin.statement.top_delinquent') }} ({{ $delinquentTenants->count() }})</div>
        <table class="data">
            <thead>
                <tr>
                    <th style="width:40%;">{{ __('admin.statement.tenant') }}</th>
                    <th class="num" style="width:15%;">{{ __('admin.statement.open_count') }}</th>
                    <th style="width:20%;">{{ __('admin.statement.oldest_due') }}</th>
                    <th class="num" style="width:25%;">{{ __('admin.statement.balance') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($delinquentTenants as $d)
                    <tr>
                        <td style="font-weight:bold;">{{ $d['name'] }}</td>
                        <td class="num">{{ $d['count'] }}</td>
                        <td>{{ $d['oldest_due'] ? \Carbon\Carbon::parse($d['oldest_due'])->format('d/m/Y') : '—' }}</td>
                        <td class="num" style="color:#B4462C;font-weight:bold;">EGP {{ number_format($d['balance'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">{{ __('admin.statement.open_invoices') }} ({{ $summary['open_count'] }})</div>
    @if($openInvoices->isEmpty())
        <div class="empty">{{ __('admin.statement.no_open_invoices') }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width:18%;">{{ __('admin.tables.invoice.number') }}</th>
                    <th style="width:20%;">{{ __('admin.statement.tenant') }}</th>
                    <th style="width:12%;">{{ __('admin.tables.invoice.due_date') }}</th>
                    <th class="num" style="width:14%;">{{ __('admin.tables.invoice.total') }}</th>
                    <th class="num" style="width:12%;">{{ __('admin.tables.invoice.paid') }}</th>
                    <th class="num" style="width:14%;">{{ __('admin.tables.invoice.balance') }}</th>
                    <th style="width:10%;">{{ __('admin.tables.common.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($openInvoices->take(25) as $inv)
                    <tr>
                        <td style="font-family:monospace;font-size:8.5pt;">{{ $inv->number }}</td>
                        <td>{{ $inv->tenant?->name ?? '—' }}</td>
                        <td>{{ $inv->due_date->format('d/m/Y') }}</td>
                        <td class="num">{{ number_format((float) $inv->total, 2) }}</td>
                        <td class="num">{{ number_format((float) $inv->paid_amount, 2) }}</td>
                        <td class="num" style="font-weight:bold;color:{{ $inv->balance > 0 ? '#B4462C' : '#2E6B4F' }};">{{ number_format((float) $inv->balance, 2) }}</td>
                        <td><span class="status-pill status-{{ $inv->status }}">{{ __("admin.statuses.invoice.{$inv->status}") }}</span></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="num">{{ __('admin.statement.total_outstanding') }}</td>
                    <td class="num" style="color:#B4462C;">EGP {{ number_format((float) $openInvoices->sum('balance'), 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        @if($openInvoices->count() > 25)
            <div class="empty">{{ __('admin.statement.truncated_note', ['shown' => 25, 'total' => $openInvoices->count()]) }}</div>
        @endif
    @endif

    <div class="section-title">{{ __('admin.statement.recent_payments') }} ({{ $payments->count() }})</div>
    @if($payments->isEmpty())
        <div class="empty">{{ __('admin.statement.no_recent_payments') }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width:18%;">{{ __('admin.tables.payment.reference') }}</th>
                    <th style="width:14%;">{{ __('admin.tables.payment.date') }}</th>
                    <th style="width:28%;">{{ __('admin.statement.tenant') }}</th>
                    <th style="width:18%;">{{ __('admin.tables.payment.method') }}</th>
                    <th class="num" style="width:22%;">{{ __('admin.tables.payment.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments->take(25) as $p)
                    <tr>
                        <td style="font-family:monospace;font-size:8.5pt;">{{ $p->reference }}</td>
                        <td>{{ $p->payment_date->format('d/m/Y') }}</td>
                        <td>{{ $p->tenant?->name ?? '—' }}</td>
                        <td>{{ \App\Models\PaymentMethod::labelFor($p->method) }}</td>
                        <td class="num" style="color:#2E6B4F;font-weight:bold;">{{ number_format((float) $p->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="num">{{ __('admin.statement.total_received') }}</td>
                    <td class="num" style="color:#2E6B4F;">EGP {{ number_format((float) $payments->sum('amount'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="footer">{{ __('admin.statement.footer') }}@if($billingEmail) {{ __('admin.statement.footer_queries') }}: {{ $billingEmail }}@endif</div>
</body>
</html>
