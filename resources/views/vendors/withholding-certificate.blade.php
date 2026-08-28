{{--
    The withholding-tax certificate (شهادة خصم) — Form 41's companion.

    Withholding is an ADVANCE payment of the SUPPLIER's own income tax, so this is the document they
    hand to their accountant to claim what has already been deducted from them. Without it the
    deduction is money they cannot account for, which is why the certificate — not the engine —
    was what kept `TaxSettings::wht_enabled` switched off.

    Issued per REGISTRATION, not per property: the supplier may have been paid out of several malls
    under one tax registration, and the certificate is evidence about the registration.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;

    $money = fn ($v) => number_format((float) $v, 2).' '.__('admin.payslip.egp');
@endphp

@extends('pdf.layout', [
    'title' => __('admin.wht_certificate.title').' — '.$vendor->name,
    'issuerCaption' => $sellerTrn ? __('admin.wht_certificate.issuer_trn').' '.$sellerTrn : null,
])

@section('document')
    <div class="doc-type">{{ __('admin.wht_certificate.title') }}</div>
    <div class="doc-meta" style="margin-top:5pt;">
        <div class="label">{{ __('admin.wht_certificate.period') }}</div>
        <strong>{{ $start->translatedFormat('d/m/Y') }} — {{ $end->translatedFormat('d/m/Y') }}</strong>
    </div>
@endsection

@section('content')
    <table class="facts gap-l">
        <tr>
            <td style="width:50%;">
                <div class="label">{{ __('admin.wht_certificate.supplier') }}</div>
                <div class="headline">{{ Bidi::isolate($vendor->name) }}</div>
                <div class="value">
                    @if ($certificate['tax_id'])
                        <div>{{ __('admin.fields.tax_id') }} {{ Bidi::isolate($certificate['tax_id']) }}</div>
                    @else
                        {{-- Stated rather than left blank: the tax authority matches a return to the
                             supplier by this number, and a certificate without one cannot be
                             reconciled by either side. --}}
                        <div>{{ __('admin.wht_certificate.no_tax_id') }}</div>
                    @endif
                    @if ($vendor->legal_name && $vendor->legal_name !== $vendor->name)
                        <div>{{ Bidi::isolate($vendor->legal_name) }}</div>
                    @endif
                </div>
            </td>
            <td class="last" style="width:50%;">
                <div class="label" style="margin-bottom:5pt;">{{ __('admin.wht_certificate.summary') }}</div>
                <table class="pair">
                    <tr>
                        <td class="k">{{ __('admin.reports.wht_base') }}</td>
                        <td class="v">{{ $money($certificate['base']) }}</td>
                    </tr>
                    <tr>
                        {{-- Withheld ÷ base, NEVER re-resolved from today's catalogue: several
                             payments in one quarter can carry different rates, and a rate revised
                             now must not rewrite a certificate already issued. --}}
                        <td class="k">{{ __('admin.reports.wht_rate') }}</td>
                        <td class="v">{{ number_format($certificate['effective_rate'], 2) }}%</td>
                    </tr>
                    <tr>
                        <td class="k"><strong>{{ __('admin.reports.wht_withheld') }}</strong></td>
                        <td class="v"><strong>{{ $money($certificate['withheld']) }}</strong></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:16%;">{{ __('admin.fields.date') }}</th>
                <th style="width:40%;">{{ __('admin.wht_certificate.bill') }}</th>
                <th class="num" style="width:22%;">{{ __('admin.reports.wht_base') }}</th>
                <th class="num" style="width:22%;">{{ __('admin.reports.wht_withheld') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($certificate['lines'] as $line)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($line['date'])->translatedFormat('d/m/Y') }}</td>
                    <td class="ink">{{ Bidi::isolate($line['reference'] ?? '—') }}</td>
                    <td class="num">{{ $money($line['base']) }}</td>
                    <td class="num ink">{{ $money($line['withheld']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">{{ __('admin.reports.wht_none') }}</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="2">{{ __('admin.reports.wht_total') }}</td>
                <td class="num">{{ $money($certificate['base']) }}</td>
                <td class="num">{{ $money($certificate['withheld']) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- The assertion itself: what was withheld, from whom, over what period. This sentence is what
         makes the page a certificate rather than a report. --}}
    <div class="panel accent" style="margin-top:20pt;">
        {{ __('admin.wht_certificate.statement', [
            // Falls back to the trading name, which is NEVER empty. `IssuingEntity::legalName()`
            // returns '' when the registered name is unconfigured — correct for the particulars
            // block above, which must not print a registration it cannot support, and wrong inside
            // a SENTENCE: unconfigured, this read "This certifies that withheld 0.00 EGP from
            // payments made to …", a certificate asserting something on nobody's behalf.
            'issuer' => $sellerLegalName ?: $issuerName,
            'supplier' => $vendor->name,
            'amount' => $money($certificate['withheld']),
            'from' => $start->translatedFormat('d/m/Y'),
            'to' => $end->translatedFormat('d/m/Y'),
        ]) }}
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-rule">&nbsp;</div>
                <div class="sig-caption">{{ __('admin.wht_certificate.authorised_signature') }}</div>
            </td>
            <td class="last">
                <div class="sig-rule">&nbsp;</div>
                <div class="sig-caption">{{ __('admin.wht_certificate.date_and_stamp') }}</div>
            </td>
        </tr>
    </table>
@endsection

@section('closing')
    {{-- The base excludes VAT — withholding prepays the supplier's INCOME tax, so it is charged on
         the consideration. A supplier reconciling this against their own gross invoices will
         otherwise wonder why the figures differ. --}}
    {{ __('admin.wht_certificate.vat_note') }}
@endsection
