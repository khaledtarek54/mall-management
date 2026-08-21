@php
    $allocated = 0.0;
    foreach ($payment->invoices as $inv) { $allocated += (float) $inv->pivot->allocated_amount; }
    $allocated = round($allocated, 2);
    $onAccount = round((float) $payment->amount - $allocated, 2);
    $receivedBy = $payment->receiver?->name ?? $payment->gateway ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.pdf.receipt.title') }} {{ $payment->reference }}</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body { color: #0F1419; font-size: 10.5pt; line-height: 1.55; margin: 0; }
        .header { border-bottom: 2px solid #0F766E; padding-bottom: 16px; margin-bottom: 24px; }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name { font-size: 22pt; font-weight: bold; color: #0F1419; letter-spacing: {{ $isRtl ? '0' : '0.5px' }}; }
        .brand-sub { color: #8C8478; font-size: 9pt; }
        .doc-title { font-size: 18pt; color: #0F766E; text-align: {{ $isRtl ? 'left' : 'right' }}; letter-spacing: {{ $isRtl ? '0' : '2px' }}; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; }
        .doc-meta { text-align: {{ $isRtl ? 'left' : 'right' }}; font-size: 9pt; color: #6B6660; margin-top: 4px; }
        .doc-meta strong { color: #0F1419; }

        .parties { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .parties td { width: 50%; vertical-align: top; padding: 0; }
        .label { font-size: 8pt; color: #8C8478; letter-spacing: {{ $isRtl ? '0' : '1.5px' }}; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; margin-bottom: 6px; }
        .party-name { font-weight: bold; font-size: 11pt; margin-bottom: 2px; }
        .party-line { color: #4A4A4A; font-size: 9.5pt; }

        .amount-box { background: #0F1419; color: #F5F0E8; padding: 16px 18px; margin-bottom: 22px; }
        .amount-box .lbl { color: #C9C3B6; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '1px' }}; font-size: 8.5pt; }
        .amount-box .amt { font-size: 20pt; font-weight: bold; font-variant-numeric: tabular-nums; }

        table.items { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.items thead th { background: #0F1419; color: #F5F0E8; text-align: {{ $isRtl ? 'right' : 'left' }}; padding: 9px 12px; font-size: 9pt; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '1px' }}; font-weight: normal; white-space: nowrap; }
        table.items thead th.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        table.items tbody td { padding: 9px 12px; border-bottom: 1px solid #E5E0D5; vertical-align: top; }
        table.items tbody td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; white-space: nowrap; font-variant-numeric: tabular-nums; }
        table.items tfoot td { padding: 9px 12px; font-weight: bold; }
        table.items tfoot td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; white-space: nowrap; font-variant-numeric: tabular-nums; }
        table.items tfoot tr.on-account td { color: #9A6F1B; font-weight: normal; }

        .status-pill { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 8pt; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '1px' }}; background: #E5F2E8; color: #2D6B3F; }
        .footer { border-top: 1px solid #E5E0D5; padding-top: 10px; margin-top: 26px; font-size: 8.5pt; color: #8C8478; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width:58%;">
                    @include('partials.issuer-logo')
                    <div class="brand-name">{{ $issuerName }}</div>
                    <div class="brand-sub">
                        @if($asset?->address){{ $asset->address }}@endif
                        @if($asset?->city), {{ $asset->city }}@endif
                    </div>
                </td>
                <td style="width:42%;">
                    <div class="doc-title">{{ __('admin.pdf.receipt.title') }}</div>
                    <div class="doc-meta">
                        <div><strong>{{ $payment->reference }}</strong></div>
                        <div>{{ __('admin.fields.payment_date') }}: {{ $payment->payment_date->format('d/m/Y') }}</div>
                        <div style="margin-top:6px;">
                            <span class="status-pill">{{ __("admin.statuses.payment.{$payment->status}") }}</span>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="label">{{ __('admin.pdf.receipt.received_from') }}</div>
                <div class="party-name">{{ $tenant->name }}</div>
                @if($tenant->legal_name && $tenant->legal_name !== $tenant->name)
                    <div class="party-line">{{ $tenant->legal_name }}</div>
                @endif
                @if($tenant->tax_id)<div class="party-line">{{ __('admin.pdf.tax_id') }}: {{ $tenant->tax_id }}</div>@endif
                @if($tenant->phone)<div class="party-line">{{ $tenant->phone }}</div>@endif
            </td>
            <td>
                <div class="label">{{ __('admin.pdf.receipt.payment_details') }}</div>
                <div class="party-line">{{ __('admin.fields.method') }}: {{ \App\Models\PaymentMethod::labelFor($payment->method) }}</div>
                @if($payment->cheque_number)
                    <div class="party-line">{{ __('admin.fields.cheque_number') }}: {{ $payment->cheque_number }}</div>
                    @if($payment->cheque_clearance_date)<div class="party-line">{{ __('admin.fields.cheque_clearance_date') }}: {{ $payment->cheque_clearance_date->format('d/m/Y') }}</div>@endif
                @endif
                @if($payment->gateway)<div class="party-line">{{ __('admin.fields.gateway') }}: {{ $payment->gateway }}</div>@endif
                @if($payment->gateway_transaction_id)<div class="party-line">{{ __('admin.fields.gateway_transaction_id') }}: {{ $payment->gateway_transaction_id }}</div>@endif
            </td>
        </tr>
    </table>

    <div class="amount-box">
        <div class="lbl">{{ __('admin.pdf.receipt.amount_received') }}</div>
        <div class="amt">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</div>
    </div>

    @if($payment->invoices->isNotEmpty())
        <div class="label">{{ __('admin.pdf.receipt.applied_to') }}</div>
        <table class="items">
            <thead>
                <tr>
                    <th style="width:70%;">{{ __('admin.resources.invoice.singular') }}</th>
                    <th class="num" style="width:30%;">{{ __('admin.fields.allocated_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payment->invoices as $inv)
                    <tr>
                        <td>{{ $inv->number }}</td>
                        <td class="num">{{ number_format((float) $inv->pivot->allocated_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>{{ __('admin.sections.allocated') }}</td>
                    <td class="num">{{ $payment->currency }} {{ number_format($allocated, 2) }}</td>
                </tr>
                @if($onAccount > 0.005)
                    <tr class="on-account">
                        <td>{{ __('admin.pdf.receipt.on_account') }}</td>
                        <td class="num">{{ $payment->currency }} {{ number_format($onAccount, 2) }}</td>
                    </tr>
                @endif
            </tfoot>
        </table>
    @endif

    @if($receivedBy)
        <div class="party-line" style="margin-top:16px;">{{ __('admin.pdf.receipt.received_by') }}: {{ $receivedBy }}</div>
    @endif

    <div class="footer">
        {{ __('admin.pdf.receipt.footer') }}
    </div>
</body>
</html>
