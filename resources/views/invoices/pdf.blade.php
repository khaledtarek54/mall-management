@php $isRtl = app()->getLocale() === 'ar'; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.pdf.tax_invoice') }} {{ $invoice->number }}</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body {
            color: #0F1419;
            font-size: 10.5pt;
            line-height: 1.55;
            margin: 0;
        }
        .header {
            border-bottom: 2px solid #0F766E;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name {
            font-size: 22pt;
            font-weight: bold;
            color: #0F1419;
            letter-spacing: {{ $isRtl ? '0' : '0.5px' }};
        }
        .brand-sub { color: #8C8478; font-size: 9pt; }
        .doc-title {
            font-size: 18pt;
            color: #0F766E;
            text-align: {{ $isRtl ? 'left' : 'right' }};
            letter-spacing: {{ $isRtl ? '0' : '4px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
        }
        .doc-meta {
            text-align: {{ $isRtl ? 'left' : 'right' }};
            font-size: 9pt;
            color: #6B6660;
            margin-top: 4px;
        }
        .doc-meta strong { color: #0F1419; }

        .parties { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .parties td { width: 50%; vertical-align: top; padding: 0; }
        .label {
            font-size: 8pt;
            color: #8C8478;
            letter-spacing: {{ $isRtl ? '0' : '1.5px' }};
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            margin-bottom: 6px;
        }
        .party-name { font-weight: bold; font-size: 11pt; margin-bottom: 2px; }
        .party-line { color: #4A4A4A; font-size: 9.5pt; }

        .period-box {
            background: #F5F0E8;
            border-left: {{ $isRtl ? '0 none' : '3px solid #0F766E' }};
            border-right: {{ $isRtl ? '3px solid #0F766E' : '0 none' }};
            padding: 10px 14px;
            margin-bottom: 20px;
            font-size: 9.5pt;
        }
        .period-box .lbl {
            color: #8C8478;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
            font-size: 8pt;
        }

        table.items { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.items thead th {
            background: #0F1419;
            color: #F5F0E8;
            text-align: {{ $isRtl ? 'right' : 'left' }};
            padding: 10px 12px;
            font-size: 9pt;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
            font-weight: normal;
            white-space: nowrap;
        }
        table.items thead th.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        table.items tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #E5E0D5;
            vertical-align: top;
            word-wrap: break-word;
        }
        table.items tbody td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; white-space: nowrap; }
        table.items tbody td.muted { color: #8C8478; font-size: 9pt; }

        .totals { width: 100%; border-collapse: collapse; }
        .totals td { padding: 7px 12px; font-size: 10pt; }
        .totals td.label {
            color: #6B6660;
            text-transform: none;
            letter-spacing: 0;
            width: 65%;
            text-align: {{ $isRtl ? 'right' : 'left' }};
            white-space: nowrap;
        }
        .totals td.value {
            text-align: {{ $isRtl ? 'left' : 'right' }};
            width: 35%;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .totals tr.grand td {
            background: #0F1419;
            color: #F5F0E8;
            font-weight: bold;
            font-size: 11.5pt;
            padding: 11px 12px;
        }
        .totals tr.balance td {
            color: #B85C38;
            font-weight: bold;
            border-top: 1px dashed #0F766E;
        }
        .totals tr.balance td.value { font-size: 11.5pt; }

        .footer {
            border-top: 1px solid #E5E0D5;
            padding-top: 10px;
            margin-top: 24px;
            font-size: 8.5pt;
            color: #8C8478;
            text-align: center;
        }
        .status-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 8pt;
            text-transform: {{ $isRtl ? 'none' : 'uppercase' }};
            letter-spacing: {{ $isRtl ? '0' : '1px' }};
        }
        .status-issued { background: #E8EFF7; color: #1F4F8C; }
        .status-paid { background: #E5F2E8; color: #2D6B3F; }
        .status-partially_paid { background: #FBF1DC; color: #9A6F1B; }
        .status-overdue { background: #F7E0DC; color: #9A2B1B; }
        .notes {
            margin-top: 18px;
            padding: 12px 14px;
            background: #FAFAF8;
            border: 1px solid #E5E0D5;
            font-size: 9.5pt;
            color: #4A4A4A;
        }
        .eta-block {
            margin-top: 14px;
            padding: 10px 14px;
            background: #ECF4F2;
            border-left: {{ $isRtl ? '0 none' : '3px solid #0F766E' }};
            border-right: {{ $isRtl ? '3px solid #0F766E' : '0 none' }};
            font-size: 9pt;
            color: #0F1419;
        }
        .eta-block .eta-line { margin-top: 3px; }
        .eta-block .eta-mono { font-family: monospace; color: #0F766E; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width:60%;">
                    <div class="brand-name">{{ $asset?->name ?? 'Atriom' }}</div>
                    <div class="brand-sub">
                        @if($asset?->address){{ $asset->address }}@endif
                        @if($asset?->city), {{ $asset->city }}@endif
                        @if($asset?->country), {{ $asset->country }}@endif
                    </div>
                </td>
                <td style="width:40%;">
                    <div class="doc-title">{{ __('admin.pdf.tax_invoice') }}</div>
                    <div class="doc-meta">
                        <div><strong>{{ $invoice->number }}</strong></div>
                        <div>{{ __('admin.fields.issue_date') }}: {{ $invoice->issue_date->format('d/m/Y') }}</div>
                        <div>{{ __('admin.fields.due_date') }}: {{ $invoice->due_date->format('d/m/Y') }}</div>
                        <div style="margin-top:6px;">
                            <span class="status-pill status-{{ $invoice->status }}">{{ __("admin.statuses.invoice.{$invoice->status}") }}</span>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="label">{{ __('admin.pdf.billed_to') }}</div>
                <div class="party-name">{{ $tenant->name }}</div>
                @if($tenant->legal_name && $tenant->legal_name !== $tenant->name)
                    <div class="party-line">{{ $tenant->legal_name }}</div>
                @endif
                @if($tenant->address)<div class="party-line">{{ $tenant->address }}</div>@endif
                @if($tenant->tax_id)<div class="party-line">{{ __('admin.pdf.tax_id') }}: {{ $tenant->tax_id }}</div>@endif
                @if($tenant->email)<div class="party-line">{{ $tenant->email }}</div>@endif
                @if($tenant->phone)<div class="party-line">{{ $tenant->phone }}</div>@endif
            </td>
            <td>
                <div class="label">{{ __('admin.pdf.lease_reference') }}</div>
                <div class="party-name">{{ $lease->reference }}</div>
                <div class="party-line">{{ __('admin.pdf.unit') }} {{ $unit?->code ?? '—' }}@if($unit?->floor) · {{ __('admin.pdf.floor') }} {{ $unit->floor->code }}@endif</div>
                @if($unit?->area_sqm)<div class="party-line">{{ number_format((float) $unit->area_sqm, 1) }} {{ __('admin.pdf.sqm') }}</div>@endif
                <div class="party-line">{{ __('admin.pdf.term') }}: {{ $lease->commencement_date->format('d/m/Y') }} – {{ $lease->expiry_date->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <div class="period-box">
        <span class="lbl">{{ __('admin.pdf.billing_period') }}</span>
        &nbsp; {{ $invoice->period_start->format('d/m/Y') }} – {{ $invoice->period_end->format('d/m/Y') }}
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:48%;">{{ __('admin.pdf.description') }}</th>
                <th class="num" style="width:18%;">{{ __('admin.pdf.amount') }}</th>
                <th class="num" style="width:14%;">{{ __('admin.pdf.vat_pct') }}</th>
                <th class="num" style="width:20%;">{{ __('admin.pdf.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ number_format((float) $item->amount, 2) }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->vat_rate, 2), '0'), '.') }}%</td>
                    <td class="num">{{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">{{ __('admin.pdf.subtotal') }}</td>
            <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('admin.pdf.vat') }}</td>
            <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->vat_amount, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">{{ __('admin.pdf.total_due') }}</td>
            <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
        @if((float) $invoice->paid_amount > 0)
            <tr>
                <td class="label">{{ __('admin.pdf.paid') }}</td>
                <td class="value">−{{ $invoice->currency }} {{ number_format((float) $invoice->paid_amount, 2) }}</td>
            </tr>
            <tr class="balance">
                <td class="label">{{ __('admin.pdf.balance') }}</td>
                <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->balance, 2) }}</td>
            </tr>
        @endif
    </table>

    @if($invoice->notes)
        <div class="notes">
            <div class="label" style="margin-bottom:4px;">{{ __('admin.pdf.notes') }}</div>
            {{ $invoice->notes }}
        </div>
    @endif

    @if($invoice->eta_submission_id)
        <div class="eta-block">
            <div class="label" style="margin-bottom:4px;">{{ __('admin.pdf.eta_reference') }}</div>
            <div class="eta-line">
                <strong>{{ __('admin.pdf.eta_submission_id') }}:</strong>
                <span class="eta-mono">{{ $invoice->eta_submission_id }}</span>
            </div>
            @if($invoice->eta_long_id)
                <div class="eta-line">
                    <strong>{{ __('admin.pdf.eta_long_id') }}:</strong>
                    <span class="eta-mono">{{ $invoice->eta_long_id }}</span>
                </div>
            @endif
            @if($invoice->eta_submitted_at)
                <div class="eta-line">
                    <strong>{{ __('admin.pdf.eta_submitted_at') }}:</strong>
                    {{ $invoice->eta_submitted_at->format('d/m/Y H:i') }}
                </div>
            @endif
        </div>
    @endif

    <div class="footer">
        {{ __('admin.pdf.footer', ['days' => $lease->payment_terms_days, 'slug' => str()->slug($asset?->name ?? 'mall')]) }}
    </div>
</body>
</html>
