@php $isRtl = ($meta['locale'] ?? app()->getLocale()) === 'ar'; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>@yield('report_title')</title>
    <style>
        * { box-sizing: border-box; }
        body { color: #14213D; font-size: 10pt; line-height: 1.5; margin: 0; }
        .header { border-bottom: 2px solid #14213D; padding-bottom: 12px; margin-bottom: 18px; }
        .brand { font-size: 18pt; font-weight: bold; color: #14213D; }
        .title { font-size: 14pt; font-weight: bold; color: #14213D; margin-top: 2px; }
        .meta { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 8.5pt; color: #7D8595; }
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
        table.report th { color: #7D8595; font-size: 8pt; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '.04em' }}; padding: 4px 6px; border-bottom: 1px solid #C9D0DC; text-align: {{ $isRtl ? 'right' : 'left' }}; }
        table.report td { padding: 4px 6px; border-bottom: 1px solid #F2F4F8; }
        .num { text-align: {{ $isRtl ? 'left' : 'right' }}; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .code { font-family: monospace; font-size: 8pt; color: #7D8595; }
        .section-title { font-weight: bold; font-size: 11pt; margin: 14px 0 4px; color: #14213D; }
        /* The same heading INSIDE a table, for a statement whose sections cannot each be their own
           table because every one of them shares the spread's column widths. */
        td.section-heading { font-weight: bold; font-size: 11pt; color: #14213D; padding-top: 12px; border-bottom: none; }
        /* A chart-group subtotal (EG-28) — lighter than the figure the section foots to,
           heavier than a leaf, so the three kinds of line stay distinguishable in print. */
        .subtotal-row td { border-top: 1px solid #C9D0DC; font-weight: 600; color: #4A5468; }
        .total-row td { border-top: 2px solid #7D8595; font-weight: bold; }
        .grand td { border-top: 2px solid #14213D; font-weight: bold; font-size: 11pt; }
        .ok { color: #2E6B4F; } .bad { color: #B4462C; }
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

    {{-- **MONEY THE STATEMENT ABOVE LEAVES OUT.** Every ledger report scopes with
         `whereIn('je.asset_id', $ids)` and `whereIn` never matches NULL, so a journal entry filed
         against no property is invisible in all of them — which is why the screen carries this
         warning (EG-27). It carried it ONLY on the screen: the PDF, the CSV and the scheduled email
         omitted the same money with nothing to say so, and those are the copies that leave the
         building. Here rather than in five templates, so a sixth statement inherits it. --}}
    @if (($unallocated ?? null) && ($unallocated['count'] ?? 0) > 0)
        <div style="margin-top:18px; padding:10px 12px; border:1px solid #E3B23C; background:#FDF6E3; font-size:9pt;">
            <div style="font-weight:bold; color:#14213D;">{{ __('admin.journal_entries.unallocated.heading') }}</div>
            <div style="margin-top:4px; color:#4A5163;">
                {{ __('admin.journal_entries.unallocated.body', [
                    'count' => number_format($unallocated['count']),
                    'total' => number_format($unallocated['total'], 2),
                    'currency' => config('app.currency', 'EGP'),
                ]) }}
            </div>
        </div>
    @endif
</body>
</html>
