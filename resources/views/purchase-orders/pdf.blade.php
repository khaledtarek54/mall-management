{{--
    The purchase order (أمر شراء) — the operator's committed intent to buy, sent to the supplier.

    NOT a tax invoice: it carries no VAT breakdown, because the tax arrives on the vendor's own bill.
    What it must carry instead is a signature block — a PO is an instruction a supplier acts on, and
    the countersigned copy is what settles a dispute about what was ordered.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;

    $currency = 'EGP';
    $lines = $po->lines;
    $total = 0.0;
    foreach ($lines as $l) { $total += (float) $l->line_value; }
    $total = round($total, 2);

    [$chipBg, $chipInk] = T::bandChip($po->status);
@endphp

@extends('pdf.layout', ['title' => __('admin.pdf.purchase_order.title').' '.($po->po_number ?? $po->reference)])

@section('document')
    <div class="doc-type">{{ __('admin.pdf.purchase_order.title') }}</div>
    <div class="doc-number">{{ Bidi::isolate($po->po_number ?? $po->reference) }}</div>
    <div>
        <span class="band-chip" style="background:{{ $chipBg }}; color:{{ $chipInk }};">
            {{ __("admin.procurement.statuses.{$po->status}") }}
        </span>
    </div>
@endsection

@section('content')
    <table class="facts gap-l">
        <tr>
            <td style="width:38%;">
                <div class="label">{{ __('admin.pdf.purchase_order.vendor') }}</div>
                @if($vendor)
                    <div class="headline">{{ Bidi::isolate($vendor->name) }}</div>
                    <div class="value">
                        @if($vendor->legal_name && $vendor->legal_name !== $vendor->name)
                            <div>{{ Bidi::isolate($vendor->legal_name) }}</div>
                        @endif
                        @if($vendor->tax_id)<div>{{ __('admin.pdf.tax_id') }} {{ Bidi::isolate($vendor->tax_id) }}</div>@endif
                        @if($vendor->phone)<div>{{ Bidi::isolate($vendor->phone) }}</div>@endif
                        @if($vendor->email)<div>{{ Bidi::isolate($vendor->email) }}</div>@endif
                    </div>
                @else
                    {{-- An ordered request with no supplier named. Said plainly rather than left
                         blank: a blank party block on an instruction to supply reads as a rendering
                         fault, and the reader cannot tell it from one. --}}
                    <div class="value">{{ __('admin.pdf.purchase_order.no_vendor') }}</div>
                @endif
            </td>
            <td class="last" style="width:62%;">
                <div class="label" style="margin-bottom:5pt;">{{ __('admin.pdf.purchase_order.order_details') }}</div>
                <table class="pair">
                    @if($po->ordered_at)
                        <tr>
                            <td class="k">{{ __('admin.procurement.fields.ordered_at') }}</td>
                            <td class="v">{{ $po->ordered_at->format('d/m/Y') }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="k">{{ __('admin.procurement.fields.reference') }}</td>
                        <td class="v">{{ Bidi::isolate($po->reference) }}</td>
                    </tr>
                    @if($po->order_reference)
                        <tr>
                            <td class="k">{{ __('admin.procurement.fields.order_reference') }}</td>
                            <td class="v">{{ Bidi::isolate($po->order_reference) }}</td>
                        </tr>
                    @endif
                    @if($po->warehouse)
                        <tr>
                            <td class="k">{{ __('admin.procurement.fields.deliver_to') }}</td>
                            <td class="v">{{ Bidi::isolate($po->warehouse->name) }}</td>
                        </tr>
                    @endif
                    @if($po->orderedBy)
                        <tr>
                            <td class="k">{{ __('admin.pdf.purchase_order.authorised_by') }}</td>
                            <td class="v">{{ Bidi::isolate($po->orderedBy->name) }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if($po->justification)
        <div class="panel">
            <div class="label">{{ __('admin.procurement.fields.justification') }}</div>
            {{ Bidi::isolateLines($po->justification) }}
        </div>
    @endif

    <table class="items" style="margin-top:6pt;">
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
                    <td class="ink">{{ Bidi::isolate($line->item?->name ?? $line->description ?? '—') }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $line->unit_cost, 2) }}</td>
                    <td class="num ink">{{ number_format((float) $line->line_value, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-wrap" style="margin-top:12pt;">
        <tr>
            <td class="spacer"></td>
            <td>
                <table class="totals">
                    <tr class="grand">
                        <td class="k">{{ __('admin.pdf.purchase_order.order_total') }}</td>
                        <td class="v">{{ $currency }} {{ number_format($total, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- A PO is an instruction, and the countersigned copy is what settles an argument about what
         was ordered. The lines were being sent with nowhere to sign them. --}}
    <table class="signatures">
        <tr>
            <td>
                <div class="sig-rule">&nbsp;</div>
                <div class="sig-caption">{{ __('admin.pdf.purchase_order.authorised_by') }} · {{ $issuerName }}</div>
            </td>
            <td class="last">
                <div class="sig-rule">&nbsp;</div>
                <div class="sig-caption">{{ __('admin.pdf.purchase_order.vendor') }}@if($vendor) · {{ Bidi::isolate($vendor->name) }}@endif</div>
            </td>
        </tr>
    </table>
@endsection

@section('closing')
    {{ __('admin.pdf.purchase_order.footer') }}
@endsection
