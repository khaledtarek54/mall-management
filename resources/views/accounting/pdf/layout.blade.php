@php $isRtl = ($meta['locale'] ?? app()->getLocale()) === 'ar'; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>@yield('report_title')</title>
    <style>
        * { box-sizing: border-box; }
        body { color: #0F1419; font-size: 10pt; line-height: 1.5; margin: 0; }
        .header { border-bottom: 2px solid #0F766E; padding-bottom: 12px; margin-bottom: 18px; }
        .brand { font-size: 18pt; font-weight: bold; color: #0F1419; }
        .title { font-size: 14pt; font-weight: bold; color: #0F766E; margin-top: 2px; }
        .meta { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 8.5pt; color: #6B7280; }
        .meta td { padding: 1px 0; }
        table.report { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        {{-- Arabic is a CURSIVE script: letter-spacing pulls the glyphs apart and breaks the joins,
             so a header that reads «الحساب» in the panel prints as disconnected letters here. Every
             other PDF in this system guards both of these on $isRtl; this layout — which renders the
             balance sheet, income statement, trial balance and cash flow, i.e. the four documents
             the accountant actually reads in Arabic — was never updated. `uppercase` is a no-op on
             Arabic rather than a defect, but it is turned off with the same switch: the two travel
             together everywhere else and splitting them here would just invite the next edit to
             restore both. --}}
        table.report th { color: #6B7280; font-size: 8pt; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '.04em' }}; padding: 4px 6px; border-bottom: 1px solid #D1D5DB; text-align: {{ $isRtl ? 'right' : 'left' }}; }
        table.report td { padding: 4px 6px; border-bottom: 1px solid #F3F4F6; }
        .num { text-align: {{ $isRtl ? 'left' : 'right' }}; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .code { font-family: monospace; font-size: 8pt; color: #6B7280; }
        .section-title { font-weight: bold; font-size: 11pt; margin: 14px 0 4px; color: #0F1419; }
        .total-row td { border-top: 2px solid #9CA3AF; font-weight: bold; }
        .grand td { border-top: 2px solid #0F766E; font-weight: bold; font-size: 11pt; }
        .ok { color: #15803D; } .bad { color: #B91C1C; }
    </style>
</head>
<body>
    <div class="header">
        @include('partials.issuer-logo')
        <div class="brand">{{ $issuerName }}</div>
        <div class="title">@yield('report_title')</div>
        <table class="meta">
            <tr>
                <td>{{ __('admin.reports.property_scope') }}: {{ $meta['property'] }}</td>
                <td class="num">{{ __('admin.fields.period') }}: {{ $meta['period'] }}</td>
            </tr>
            <tr>
                <td colspan="2">{{ $meta['generated_on'] }}</td>
            </tr>
        </table>
    </div>

    @yield('content')
</body>
</html>
