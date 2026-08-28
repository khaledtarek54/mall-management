{{--
    The receipt voucher (سند قبض) — the proof a tenant keeps.

    NOT a tax invoice: it acknowledges cash received and carries no VAT breakdown. The one figure
    that matters is the amount, so it is set as a panel rather than as another row in another table —
    a receipt is read by someone checking one number.

    `$allocated` / `$onAccount` / `$receivedBy` are derived here rather than in the service because
    they are presentation arithmetic over data the service already passed, and the split between
    them is what the "applied to" table exists to show.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;

    $allocated = 0.0;
    foreach ($payment->invoices as $inv) { $allocated += (float) $inv->pivot->allocated_amount; }
    $allocated = round($allocated, 2);
    $onAccount = round((float) $payment->amount - $allocated, 2);
    $receivedBy = $payment->receiver?->name ?? $payment->gateway ?? null;

    [$chipBg, $chipInk] = T::chip($payment->status);
@endphp

@extends('pdf.layout', ['title' => __('admin.pdf.receipt.title').' '.$payment->reference])

@section('document')
    <div class="doc-type">{{ __('admin.pdf.receipt.title') }}</div>
    <div class="doc-number">{{ Bidi::isolate($payment->reference) }}</div>
    <div style="margin-top:7pt;">
        <span class="chip" style="background:{{ $chipBg }}; color:{{ $chipInk }};">
            {{ __("admin.statuses.payment.{$payment->status}") }}
        </span>
    </div>
@endsection

@section('content')
    <table class="facts gap-l">
        <tr>
            <td style="width:38%;">
                <div class="label">{{ __('admin.pdf.receipt.received_from') }}</div>
                <div class="headline">{{ Bidi::isolate($tenant->name) }}</div>
                <div class="value">
                    @if($tenant->legal_name && $tenant->legal_name !== $tenant->name)
                        <div>{{ Bidi::isolate($tenant->legal_name) }}</div>
                    @endif
                    @if($tenant->tax_id)<div>{{ __('admin.pdf.tax_id') }} {{ Bidi::isolate($tenant->tax_id) }}</div>@endif
                    @if($tenant->phone)<div>{{ Bidi::isolate($tenant->phone) }}</div>@endif
                </div>
            </td>
            <td class="last" style="width:62%;">
                <div class="label" style="margin-bottom:5pt;">{{ __('admin.pdf.receipt.payment_details') }}</div>
                <table class="pair">
                    <tr>
                        <td class="k">{{ __('admin.fields.payment_date') }}</td>
                        <td class="v">{{ $payment->payment_date->format('d/m/Y') }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('admin.fields.method') }}</td>
                        {{-- Through the catalogue, never a lang key: an operator-added rail (Fawry,
                             a new wallet) has no translation and would print its raw code here. --}}
                        <td class="v">{{ \App\Models\PaymentMethod::labelFor($payment->method) }}</td>
                    </tr>
                    @if($payment->cheque_number)
                        <tr>
                            <td class="k">{{ __('admin.fields.cheque_number') }}</td>
                            <td class="v">{{ Bidi::isolate($payment->cheque_number) }}</td>
                        </tr>
                        @if($payment->cheque_clearance_date)
                            <tr>
                                <td class="k">{{ __('admin.fields.cheque_clearance_date') }}</td>
                                <td class="v">{{ $payment->cheque_clearance_date->format('d/m/Y') }}</td>
                            </tr>
                        @endif
                    @endif
                    @if($payment->gateway)
                        <tr>
                            <td class="k">{{ __('admin.fields.gateway') }}</td>
                            <td class="v">{{ Bidi::isolate($payment->gateway) }}</td>
                        </tr>
                    @endif
                    @if($payment->gateway_transaction_id)
                        <tr>
                            <td class="k">{{ __('admin.fields.gateway_transaction_id') }}</td>
                            <td class="v">{{ Bidi::isolate($payment->gateway_transaction_id) }}</td>
                        </tr>
                    @endif
                    @if($receivedBy)
                        <tr>
                            <td class="k">{{ __('admin.pdf.receipt.received_by') }}</td>
                            <td class="v">{{ Bidi::isolate($receivedBy) }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- The whole point of the document, set as the largest thing on the page. --}}
    <div class="panel accent" style="padding:12pt 14pt;">
        <div class="label">{{ __('admin.pdf.receipt.amount_received') }}</div>
        <div style="font-size:22pt; font-weight:bold; color:{{ T::INK }}; line-height:1.2; margin-top:2pt;">
            {{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}
        </div>
    </div>

    @if($payment->invoices->isNotEmpty())
        <div class="label" style="margin-top:20pt; margin-bottom:5pt;">{{ __('admin.pdf.receipt.applied_to') }}</div>
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
                        <td class="ink">{{ Bidi::isolate($inv->number) }}</td>
                        <td class="num ink">{{ number_format((float) $inv->pivot->allocated_amount, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>{{ __('admin.sections.allocated') }}</td>
                    <td class="num">{{ $payment->currency }} {{ number_format($allocated, 2) }}</td>
                </tr>
                {{-- Money received and not yet applied to anything. It sits on the tenant's account
                     as a credit, so saying so on the receipt is the difference between "you have
                     overpaid" and a figure the tenant cannot reconcile against their own invoices. --}}
                @if($onAccount > 0.005)
                    <tr>
                        <td class="muted">{{ __('admin.pdf.receipt.on_account') }}</td>
                        <td class="num muted">{{ $payment->currency }} {{ number_format($onAccount, 2) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endif
@endsection

@section('closing')
    {{ __('admin.pdf.receipt.footer') }}
@endsection
