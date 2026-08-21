@php $isRtl = app()->getLocale() === 'ar'; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.statement.title') }} {{ $tenant->name }}</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body {
            color: #0F1419;
            font-size: 10pt;
            line-height: 1.5;
            margin: 0;
        }
        .header {
            border-bottom: 2px solid #0F766E;
            padding-bottom: 14px;
            margin-bottom: 20px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name {
            font-size: 20pt;
            font-weight: bold;
            color: #0F1419;
            letter-spacing: {{ $isRtl ? '0' : '0.5px' }};
        }
        .brand-sub { color: #8C8478; font-size: 9pt; }
        .doc-title {
            font-size: 16pt;
            color: #0F766E;
            text-align: {{ $isRtl ? 'left' : 'right' }};
            letter-spacing: {{ $isRtl ? '0' : '3px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
        }
        .doc-meta { text-align: {{ $isRtl ? 'left' : 'right' }}; font-size: 9pt; color: #6B6660; margin-top: 4px; }
        .doc-meta strong { color: #0F1419; }

        .label {
            font-size: 8pt;
            color: #8C8478;
            letter-spacing: {{ $isRtl ? '0' : '1.5px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            margin-bottom: 4px;
        }
        .party-name { font-weight: bold; font-size: 11pt; margin-bottom: 2px; }
        .party-line { color: #4A4A4A; font-size: 9.5pt; }

        .summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: #F5F0E8;
            border-left: {{ $isRtl ? '0 none' : '3px solid #0F766E' }};
            border-right: {{ $isRtl ? '3px solid #0F766E' : '0 none' }};
        }
        .summary td {
            padding: 10px 14px;
            width: 25%;
            border-left: {{ $isRtl ? '1px solid rgba(201,169,97,0.2)' : '0 none' }};
            border-right: {{ $isRtl ? '0 none' : '1px solid rgba(201,169,97,0.2)' }};
        }
        .summary td:last-child { border-left: 0 none; border-right: 0 none; }
        .summary .stat-label {
            font-size: 8pt;
            color: #8C8478;
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
        }
        .summary .stat-value { font-size: 14pt; font-weight: bold; color: #0F1419; margin-top: 4px; }
        .summary .stat-value.warn { color: #B85C38; }

        .section-title {
            font-size: 11pt;
            font-weight: bold;
            color: #0F1419;
            margin: 18px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #0F766E;
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
        }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data thead th {
            background: #0F1419;
            color: #F5F0E8;
            text-align: {{ $isRtl ? 'right' : 'left' }};
            /* 8px/10px at 9.5pt could not fit seven columns across A4: widening the status column
               pushed the wrap into the invoice number, the due date and the paid figure instead.
               Measured against mPDF's own font metrics, not guessed — see
               StatementColumnsFitTest. */
            padding: 6px 6px;
            font-size: 8pt;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
            font-weight: normal;
        }
        table.data thead th.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        table.data tbody td {
            padding: 6px 6px;
            border-bottom: 1px solid #E5E0D5;
            vertical-align: top;
            font-size: 8.5pt;
        }
        table.data tbody td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        table.data tbody td.muted { color: #8C8478; }
        table.data tfoot td {
            padding: 6px 6px;
            font-weight: bold;
            border-top: 2px solid #0F1419;
            background: #FAFAF8;
        }
        table.data tfoot td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }

        .status-pill {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 7pt;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '0.5px' }};
        }
        .status-paid { background: #E5F2E8; color: #2D6B3F; }
        .status-issued { background: #E8EFF7; color: #1F4F8C; }
        .status-partially_paid { background: #FBF1DC; color: #9A6F1B; }
        .status-overdue { background: #F7E0DC; color: #9A2B1B; }

        .empty { color: #8C8478; font-size: 9.5pt; text-align: center; padding: 14px; font-style: italic; }

        .footer {
            border-top: 1px solid #E5E0D5;
            padding-top: 8px;
            margin-top: 18px;
            font-size: 8pt;
            color: #8C8478;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width:60%;">
                    <div class="brand-name">{{ $issuerName }}</div>
                    <div class="brand-sub">
                        @if($asset?->address){{ $asset->address }}@endif
                        @if($asset?->city), {{ $asset->city }}@endif
                    </div>
                </td>
                <td style="width:40%;">
                    <div class="doc-title">{{ __('admin.statement.title') }}</div>
                    <div class="doc-meta">
                        <div>{{ __('admin.statement.as_of') }}: <strong>{{ $asOf->format('d/m/Y') }}</strong></div>
                        <div>{{ __('admin.statement.period_label') }}: {{ $since->format('d/m/Y') }} – {{ $asOf->format('d/m/Y') }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
        <tr>
            <td style="width:50%;vertical-align:top;">
                <div class="label">{{ __('admin.statement.tenant') }}</div>
                <div class="party-name">{{ $tenant->name }}</div>
                @if($tenant->legal_name && $tenant->legal_name !== $tenant->name)
                    <div class="party-line">{{ $tenant->legal_name }}</div>
                @endif
                @if($tenant->tax_id)<div class="party-line">{{ __('admin.pdf.tax_id') }}: {{ $tenant->tax_id }}</div>@endif
                @if($tenant->email)<div class="party-line">{{ $tenant->email }}</div>@endif
                @if($tenant->phone)<div class="party-line">{{ $tenant->phone }}</div>@endif
            </td>
            <td style="width:50%;vertical-align:top;">
                <div class="label">{{ __('admin.statement.leases') }}</div>
                @forelse($tenant->leases->where('status', 'active') as $lease)
                    <div class="party-line">
                        <strong>{{ $lease->reference }}</strong> ·
                        {{ __('admin.pdf.unit') }} {{ $lease->unit?->code ?? '—' }} ·
                        {{ $lease->commencement_date->format('d/m/Y') }} – {{ $lease->expiry_date->format('d/m/Y') }}
                    </div>
                @empty
                    <div class="party-line muted">—</div>
                @endforelse
            </td>
        </tr>
    </table>

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
                <div class="stat-value">EGP {{ number_format($summary['total_paid'], 2) }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">{{ __('admin.statement.open_invoices') }} ({{ $summary['open_count'] }})</div>
    @if($openInvoices->isEmpty())
        <div class="empty">{{ __('admin.statement.no_open_invoices') }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width:19%;">{{ __('admin.tables.invoice.number') }}</th>
                    <th style="width:14%;">{{ __('admin.tables.invoice.period') }}</th>
                    <th style="width:12%;">{{ __('admin.tables.invoice.due_date') }}</th>
                    <th class="num" style="width:13%;">{{ __('admin.tables.invoice.total') }}</th>
                    <th class="num" style="width:12%;">{{ __('admin.tables.invoice.paid') }}</th>
                    <th class="num" style="width:13%;">{{ __('admin.tables.invoice.balance') }}</th>
                    {{-- 8% could not hold "Partially paid": the header broke to "STATU S" and the pill
                         to "PARTIAL LY PAID" on the document the tenant receives. --}}
                    <th style="width:17%;">{{ __('admin.tables.common.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($openInvoices as $inv)
                    <tr>
                        <td style="font-family:monospace;font-size:8pt;">{{ $inv->number }}</td>
                        {{-- The SPAN, not the first month. This printed "Apr 2026" against a 240,300
                             quarterly invoice covering April–June, so the tenant reads a quarter's
                             rent as one month's and disputes it. Only a single-month period collapses
                             to one label. --}}
                        <td>{{ $inv->periodLabel() }}</td>
                        <td>{{ $inv->due_date->format('d/m/Y') }}</td>
                        <td class="num">{{ number_format((float) $inv->total, 2) }}</td>
                        <td class="num">{{ number_format((float) $inv->paid_amount, 2) }}</td>
                        <td class="num" style="font-weight:bold;color:{{ $inv->balance > 0 ? '#B85C38' : '#2D6B3F' }};">{{ number_format((float) $inv->balance, 2) }}</td>
                        <td><span class="status-pill status-{{ $inv->status }}">{{ __("admin.statuses.invoice.{$inv->status}") }}</span></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="num">{{ __('admin.statement.total_outstanding') }}</td>
                    {{-- Spans the balance AND status columns. The total carries an "EGP " prefix the
                         body cells do not, so it needs more room than the column it sits under — at
                         13% it wrapped to "EGP / 300,500.00" while every row above it fitted. --}}
                    <td colspan="2" class="num" style="color:#B85C38;">EGP {{ number_format((float) $openInvoices->sum('balance'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- Credits settle an invoice exactly as a payment does, and they were counted in Total Settled
         while appearing nowhere on the page. Only rendered when there are any: an empty "Credits"
         table on every ordinary statement is noise, and unlike payments a tenant does not expect
         one. --}}
    @if($credits->isNotEmpty())
        <div class="section-title">{{ __('admin.statement.credits_applied') }} ({{ $credits->count() }})</div>
        <table class="data">
            <thead>
                <tr>
                    <th style="width:18%;">{{ __('admin.tables.credit_note.number') }}</th>
                    <th style="width:14%;">{{ __('admin.tables.payment.date') }}</th>
                    <th style="width:20%;">{{ __('admin.tables.invoice.number') }}</th>
                    <th style="width:30%;">{{ __('admin.fields.reason') }}</th>
                    <th class="num" style="width:18%;">{{ __('admin.tables.credit_note.applied') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($credits as $cn)
                    <tr>
                        <td style="font-family:monospace;font-size:8pt;">{{ $cn->number }}</td>
                        <td>{{ $cn->issue_date?->format('d/m/Y') ?? '—' }}</td>
                        <td style="font-family:monospace;font-size:8pt;">{{ $cn->invoice?->number ?? '—' }}</td>
                        <td>{{ $cn->reason ? __('admin.enums.credit_note_reason.'.$cn->reason, [], $cn->reason) : '—' }}</td>
                        <td class="num" style="color:#2D6B3F;font-weight:bold;">{{ number_format((float) $cn->applied_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="num">{{ __('admin.statement.total_credited') }}</td>
                    <td class="num" style="color:#2D6B3F;">EGP {{ number_format((float) $credits->sum('applied_amount'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="section-title">{{ __('admin.statement.recent_payments') }} ({{ $payments->count() }})</div>
    @if($payments->isEmpty())
        <div class="empty">{{ __('admin.statement.no_recent_payments') }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width:18%;">{{ __('admin.tables.payment.reference') }}</th>
                    <th style="width:18%;">{{ __('admin.tables.payment.date') }}</th>
                    <th style="width:18%;">{{ __('admin.tables.payment.method') }}</th>
                    <th class="num" style="width:18%;">{{ __('admin.tables.payment.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $p)
                    <tr>
                        <td style="font-family:monospace;font-size:8pt;">{{ $p->reference }}</td>
                        <td>{{ $p->payment_date->format('d/m/Y') }}</td>
                        <td>{{ \App\Models\PaymentMethod::labelFor($p->method) }}</td>
                        <td class="num" style="color:#2D6B3F;font-weight:bold;">{{ number_format((float) $p->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="num">{{ __('admin.statement.total_received') }}</td>
                    <td class="num" style="color:#2D6B3F;">EGP {{ number_format((float) $payments->sum('amount'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="footer">{{ __('admin.statement.footer') }}@if($billingEmail) {{ __('admin.statement.footer_queries') }}: {{ $billingEmail }}@endif</div>
</body>
</html>
