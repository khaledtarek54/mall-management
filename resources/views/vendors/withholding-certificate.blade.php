@php
    $isRtl = app()->getLocale() === 'ar';
    $money = fn ($v) => number_format((float) $v, 2).' '.__('admin.payslip.egp');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.wht_certificate.title') }} — {{ $vendor->name }}</title>
    <style>
        @page { margin: 28px 32px; }
        * { box-sizing: border-box; }
        body { color: #0F1419; font-size: 10.5pt; line-height: 1.55; margin: 0; }
        .header { border-bottom: 2px solid #0F766E; padding-bottom: 14px; margin-bottom: 20px; }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name { font-size: 20pt; font-weight: bold; color: #0F1419; }
        .brand-sub { color: #8C8478; font-size: 9pt; }
        .doc-title { font-size: 16pt; color: #0F766E; text-align: {{ $isRtl ? 'left' : 'right' }}; }
        .doc-meta { text-align: {{ $isRtl ? 'left' : 'right' }}; font-size: 9pt; color: #6B6660; margin-top: 4px; }
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .parties td { width: 50%; vertical-align: top; padding: 0; }
        .label { font-size: 8pt; color: #8C8478; margin-bottom: 4px; }
        .party-name { font-weight: bold; font-size: 11pt; margin-bottom: 2px; }
        .party-line { color: #4A4A4A; font-size: 9.5pt; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.lines th { text-align: {{ $isRtl ? 'right' : 'left' }}; font-size: 8.5pt; color: #8C8478;
                         border-bottom: 1px solid #E7E1D6; padding: 6px 8px;
                         {{-- Uppercase breaks Arabic glyph joining, so it is a Latin-only flourish. --}}
                         text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; }
        table.lines td { padding: 7px 8px; border-bottom: 1px solid #F0EBE1; }
        table.lines td.num, table.lines th.num { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        .total-row td { border-top: 2px solid #0F766E; border-bottom: none; font-size: 12pt;
                        font-weight: bold; padding-top: 12px; }
        .statement { margin-top: 24px; padding: 12px 14px; background: #F7F5F0; font-size: 9.5pt; line-height: 1.7; }
        .signature { margin-top: 42px; width: 100%; border-collapse: collapse; }
        .signature td { width: 50%; vertical-align: bottom; padding-top: 34px; font-size: 9pt; color: #6B6660; }
        .sig-line { border-top: 1px solid #8C8478; padding-top: 6px; width: 78%; }
        .footnote { margin-top: 18px; font-size: 8pt; color: #8C8478; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td>
                    {{-- The registered ENTITY, not a mall: a withholding certificate is evidence
                         about a deduction made under one tax registration, and the supplier may
                         have been paid from several properties. --}}
                    @include('partials.issuer-logo')
                    <div class="brand-name">{{ $issuerName }}</div>
                    <div class="brand-sub">{{ $sellerLegalName }}</div>
                    @if ($sellerTrn)
                        <div class="brand-sub">{{ __('admin.wht_certificate.issuer_trn') }}: {{ $sellerTrn }}</div>
                    @endif
                </td>
                <td>
                    <div class="doc-title">{{ __('admin.wht_certificate.title') }}</div>
                    <div class="doc-meta">
                        {{ __('admin.wht_certificate.period') }}:
                        {{ $start->translatedFormat('d/m/Y') }} — {{ $end->translatedFormat('d/m/Y') }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="label">{{ __('admin.wht_certificate.supplier') }}</div>
                <div class="party-name">{{ $vendor->name }}</div>
                @if ($certificate['tax_id'])
                    <div class="party-line">{{ __('admin.fields.tax_id') }}: {{ $certificate['tax_id'] }}</div>
                @else
                    {{-- Stated rather than left blank: the ETA matches a return to the supplier by
                         this number, and a certificate without one cannot be reconciled. --}}
                    <div class="party-line">{{ __('admin.wht_certificate.no_tax_id') }}</div>
                @endif
                @if ($vendor->legal_name && $vendor->legal_name !== $vendor->name)
                    <div class="party-line">{{ $vendor->legal_name }}</div>
                @endif
            </td>
            <td>
                <div class="label">{{ __('admin.wht_certificate.summary') }}</div>
                <div class="party-line">{{ __('admin.reports.wht_base') }}: {{ $money($certificate['base']) }}</div>
                <div class="party-line">{{ __('admin.reports.wht_rate') }}: {{ number_format($certificate['effective_rate'], 2) }}%</div>
                <div class="party-line"><strong>{{ __('admin.reports.wht_withheld') }}: {{ $money($certificate['withheld']) }}</strong></div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('admin.fields.date') }}</th>
                <th>{{ __('admin.wht_certificate.bill') }}</th>
                <th class="num">{{ __('admin.reports.wht_base') }}</th>
                <th class="num">{{ __('admin.reports.wht_withheld') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($certificate['lines'] as $line)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($line['date'])->translatedFormat('d/m/Y') }}</td>
                    <td>{{ $line['reference'] ?? '—' }}</td>
                    <td class="num">{{ $money($line['base']) }}</td>
                    <td class="num">{{ $money($line['withheld']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">{{ __('admin.reports.wht_none') }}</td></tr>
            @endforelse
            <tr class="total-row">
                <td colspan="2">{{ __('admin.reports.wht_total') }}</td>
                <td class="num">{{ $money($certificate['base']) }}</td>
                <td class="num">{{ $money($certificate['withheld']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="statement">
        {{ __('admin.wht_certificate.statement', [
            'issuer' => $sellerLegalName,
            'supplier' => $vendor->name,
            'amount' => $money($certificate['withheld']),
            'from' => $start->translatedFormat('d/m/Y'),
            'to' => $end->translatedFormat('d/m/Y'),
        ]) }}
    </div>

    <table class="signature">
        <tr>
            <td><div class="sig-line">{{ __('admin.wht_certificate.authorised_signature') }}</div></td>
            <td><div class="sig-line">{{ __('admin.wht_certificate.date_and_stamp') }}</div></td>
        </tr>
    </table>

    {{-- The base excludes VAT, and a supplier reconciling this against their own invoices will
         otherwise wonder why the figures differ from their gross. Said on the document. --}}
    <div class="footnote">{{ __('admin.wht_certificate.vat_note') }}</div>
</body>
</html>
