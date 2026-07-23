@php
    $currency = 'EGP';
    $lines = $po->lines;
    $total = 0.0;
    foreach ($lines as $l) { $total += (float) $l->line_value; }
    $total = round($total, 2);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.pdf.purchase_order.title') }} {{ $po->po_number ?? $po->reference }}</title>
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

        table.items { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.items thead th { background: #0F1419; color: #F5F0E8; text-align: {{ $isRtl ? 'right' : 'left' }}; padding: 9px 12px; font-size: 9pt; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '1px' }}; font-weight: normal; white-space: nowrap; }
        table.items thead th.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        table.items tbody td { padding: 9px 12px; border-bottom: 1px solid #E5E0D5; vertical-align: top; }
        table.items tbody td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; white-space: nowrap; font-variant-numeric: tabular-nums; }
        table.items tfoot td { padding: 10px 12px; font-weight: bold; }
        table.items tfoot td.num { text-align: {{ $isRtl ? 'left' : 'right' }}; white-space: nowrap; font-variant-numeric: tabular-nums; }

        .total-box { background: #0F1419; color: #F5F0E8; padding: 14px 18px; margin: 6px 0 22px; }
        .total-box .lbl { color: #C9C3B6; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '1px' }}; font-size: 8.5pt; }
        .total-box .amt { font-size: 18pt; font-weight: bold; font-variant-numeric: tabular-nums; }

        .status-pill { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 8pt; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '1px' }}; background: #E5F2E8; color: #2D6B3F; }
        .justification { background: #FBF9F4; border: 1px solid #EDE7DA; padding: 10px 12px; margin-bottom: 20px; font-size: 9.5pt; color: #4A4A4A; }
        .footer { border-top: 1px solid #E5E0D5; padding-top: 10px; margin-top: 26px; font-size: 8.5pt; color: #8C8478; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width:58%;">
                    <div class="brand-name">{{ $asset?->name ?? 'Atriom' }}</div>
                    <div class="brand-sub">
                        @if($asset?->address){{ $asset->address }}@endif
                        @if($asset?->city), {{ $asset->city }}@endif
                    </div>
                </td>
                <td style="width:42%;">
                    <div class="doc-title">{{ __('admin.pdf.purchase_order.title') }}</div>
                    <div class="doc-meta">
                        <div><strong>{{ $po->po_number ?? $po->reference }}</strong></div>
                        @if($po->ordered_at)<div>{{ __('admin.procurement.fields.ordered_at') }}: {{ $po->ordered_at->format('d/m/Y') }}</div>@endif
                        <div style="margin-top:6px;">
                            <span class="status-pill">{{ __("admin.procurement.statuses.{$po->status}") }}</span>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="label">{{ __('admin.pdf.purchase_order.vendor') }}</div>
                @if($vendor)
                    <div class="party-name">{{ $vendor->name }}</div>
                    @if($vendor->legal_name && $vendor->legal_name !== $vendor->name)
                        <div class="party-line">{{ $vendor->legal_name }}</div>
                    @endif
                    @if($vendor->tax_id)<div class="party-line">{{ __('admin.pdf.tax_id') }}: {{ $vendor->tax_id }}</div>@endif
                    @if($vendor->phone)<div class="party-line">{{ $vendor->phone }}</div>@endif
                    @if($vendor->email)<div class="party-line">{{ $vendor->email }}</div>@endif
                @else
                    <div class="party-line">{{ __('admin.pdf.purchase_order.no_vendor') }}</div>
                @endif
            </td>
            <td>
                <div class="label">{{ __('admin.pdf.purchase_order.order_details') }}</div>
                <div class="party-line">{{ __('admin.procurement.fields.reference') }}: {{ $po->reference }}</div>
                @if($po->order_reference)<div class="party-line">{{ __('admin.procurement.fields.order_reference') }}: {{ $po->order_reference }}</div>@endif
                @if($po->warehouse)<div class="party-line">{{ __('admin.procurement.fields.deliver_to') }}: {{ $po->warehouse->name }}</div>@endif
                @if($po->orderedBy)<div class="party-line">{{ __('admin.pdf.purchase_order.authorised_by') }}: {{ $po->orderedBy->name }}</div>@endif
            </td>
        </tr>
    </table>

    @if($po->justification)
        <div class="justification"><strong>{{ __('admin.procurement.fields.justification') }}:</strong> {{ $po->justification }}</div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th style="width:46%;">{{ __('admin.procurement.fields.item') }}</th>
                <th class="num" style="width:16%;">{{ __('admin.procurement.fields.quantity') }}</th>
                <th class="num" style="width:18%;">{{ __('admin.procurement.fields.unit_cost') }}</th>
                <th class="num" style="width:20%;">{{ __('admin.procurement.fields.line_value') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
                <tr>
                    <td>{{ $line->item?->name ?? $line->description ?? '—' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $line->unit_cost, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->line_value, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">{{ __('admin.procurement.fields.total_value') }}</td>
                <td class="num">{{ $currency }} {{ number_format($total, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="total-box">
        <div class="lbl">{{ __('admin.pdf.purchase_order.order_total') }}</div>
        <div class="amt">{{ $currency }} {{ number_format($total, 2) }}</div>
    </div>

    <div class="footer">
        {{ __('admin.pdf.purchase_order.footer') }}
    </div>
</body>
</html>
