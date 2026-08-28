@php
    $isRtl = app()->getLocale() === 'ar';
    $fmt = fn ($v) => number_format((float) $v, 2).' '.($asset->currency ?? 'EGP');
    $align = $isRtl ? 'left' : 'right';
    // The frozen per-account breakdown snapshotted at generate time; localized name per row.
    $breakdown = $run->income_breakdown ?? ['revenue' => [], 'expense' => []];
    $rowName = fn ($r) => $isRtl ? ($r['name_ar'] ?? $r['name_en'] ?? $r['code']) : ($r['name_en'] ?? $r['name_ar'] ?? $r['code']);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('admin.owner_statements.singular') }} — {{ $statement->reference }}</title>
    <style>
        /* No @page rule: page geometry (size, margins, the band the running footer sits in)
           belongs to App\Support\Pdf\PdfDocument. A template that set its own margins here
           silently overrode mpdf's and left no room beneath the body, so the running footer
           carrying the document reference and `page x of y` rendered nowhere at all. */
        * { box-sizing: border-box; }
        body { color: #0F1419; font-size: 10pt; line-height: 1.5; margin: 0; }
        .header { border-bottom: 2px solid #0F766E; padding-bottom: 14px; margin-bottom: 20px; }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name { font-size: 20pt; font-weight: bold; color: #0F1419; }
        .brand-sub { color: #8C8478; font-size: 9pt; }
        .doc-title { font-size: 15pt; color: #0F766E; text-align: {{ $align }}; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '2px' }}; }
        .doc-meta { text-align: {{ $align }}; font-size: 9pt; color: #6B6660; margin-top: 4px; }
        .doc-meta strong { color: #0F1419; }
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .parties td { width: 50%; vertical-align: top; padding: 0 6px; }
        .label { font-size: 8pt; color: #8C8478; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '1.2px' }}; margin-bottom: 3px; }
        .party-name { font-weight: bold; font-size: 11pt; margin-bottom: 2px; }
        .party-line { color: #4A4A4A; font-size: 9.5pt; }
        table.figures { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.figures td { padding: 8px 10px; border-bottom: 1px solid #E7E1D6; }
        table.figures td.amt { text-align: {{ $align }}; white-space: nowrap; }
        tr.net td { border-top: 2px solid #0F766E; border-bottom: none; font-weight: bold; font-size: 11.5pt; background: #F5F0E8; }
        tr.sub td { color: #6B6660; padding-{{ $isRtl ? 'right' : 'left' }}: 20px; font-size: 9.5pt; }
        tr.section td { background: #0F1419; color: #F5F0E8; font-size: 8.5pt; text-transform: {{ $isRtl ? 'none' : 'uppercase' }}; letter-spacing: {{ $isRtl ? '0' : '1px' }}; padding: 6px 10px; }
        tr.subtotal td { font-weight: bold; border-bottom: 1px solid #0F766E; }
        .footnote { margin-top: 22px; font-size: 8.5pt; color: #8C8478; border-top: 1px solid #E7E1D6; padding-top: 8px; }
        .status { display: inline-block; padding: 1px 8px; border-radius: 8px; font-size: 8pt; background: #0F766E; color: #fff; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td>
                    {{-- The OPERATOR, who prepared this statement — not the property, which is
                         named in the party block below, and not "Atriom", which is the software.
                         The owner is being told what their managing agent collected and spent on
                         their behalf; the agent's name is the one that belongs at the top. --}}
                    @include('partials.issuer-logo')
                    <div class="brand-name">{{ $issuerName }}</div>
                    <div class="brand-sub">{{ __('admin.owner_statements.plural') }}</div>
                </td>
                <td>
                    <div class="doc-title">{{ __('admin.owner_statements.singular') }}</div>
                    <div class="doc-meta">
                        <strong>{{ $statement->reference }}</strong><br>
                        {{ __('admin.owner_statements.fields.period') }}:
                        <strong>{{ \Illuminate\Support\Carbon::parse($run->period_start)->format('d M Y') }}
                            – {{ \Illuminate\Support\Carbon::parse($run->period_end)->format('d M Y') }}</strong><br>
                        <span class="status">{{ __('admin.owner_statements.statuses.'.$statement->status) }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="label">{{ __('admin.owner_statements.fields.owner') }}</div>
                <div class="party-name">{{ $owner?->name ?? '—' }}</div>
                <div class="party-line">{{ __('admin.owner_statements.fields.ownership_percentage') }}:
                    {{ number_format((float) $statement->ownership_percentage, 2) }}%</div>
            </td>
            <td>
                <div class="label">{{ __('admin.owner_statements.fields.property') }}</div>
                <div class="party-name">{{ $asset->name }}</div>
                <div class="party-line">{{ $asset->city }}</div>
            </td>
        </tr>
    </table>

    {{-- Itemized property P&L — what the revenue was and where the expenses went, the whole point
         of a statement. Falls back to the bare totals for pre-snapshot (legacy) runs. --}}
    <table class="figures">
        @if (! empty($breakdown['revenue']) || ! empty($breakdown['expense']))
            <tr class="section"><td colspan="2">{{ __('admin.owner_statements.pdf.revenue') }}</td></tr>
            @forelse ($breakdown['revenue'] as $r)
                <tr class="sub">
                    <td>{{ $rowName($r) }}</td>
                    <td class="amt">{{ $fmt($r['amount']) }}</td>
                </tr>
            @empty
                <tr class="sub"><td>{{ __('admin.owner_statements.pdf.none') }}</td><td class="amt">{{ $fmt(0) }}</td></tr>
            @endforelse
            <tr class="subtotal">
                <td>{{ __('admin.owner_statements.fields.total_revenue') }}</td>
                <td class="amt">{{ $fmt($run->total_revenue) }}</td>
            </tr>

            <tr class="section"><td colspan="2">{{ __('admin.owner_statements.pdf.expenses') }}</td></tr>
            @forelse ($breakdown['expense'] as $r)
                <tr class="sub">
                    <td>{{ $rowName($r) }}</td>
                    <td class="amt">({{ $fmt($r['amount']) }})</td>
                </tr>
            @empty
                <tr class="sub"><td>{{ __('admin.owner_statements.pdf.none') }}</td><td class="amt">({{ $fmt(0) }})</td></tr>
            @endforelse
            <tr class="subtotal">
                <td>{{ __('admin.owner_statements.fields.total_expense') }}</td>
                <td class="amt">({{ $fmt($run->total_expense) }})</td>
            </tr>
        @else
            <tr>
                <td>{{ __('admin.owner_statements.fields.total_revenue') }}</td>
                <td class="amt">{{ $fmt($run->total_revenue) }}</td>
            </tr>
            <tr>
                <td>{{ __('admin.owner_statements.fields.total_expense') }}</td>
                <td class="amt">({{ $fmt($run->total_expense) }})</td>
            </tr>
        @endif
        <tr class="net">
            <td>{{ __('admin.owner_statements.fields.net_operating_income') }}</td>
            <td class="amt">{{ $fmt($run->net_operating_income) }}</td>
        </tr>
        @if ((float) $statement->weight < 0.999999)
            <tr class="sub">
                <td>{{ __('admin.owner_statements.fields.owner_share') }}
                    ({{ number_format((float) $statement->weight * 100, 2) }}%)</td>
                <td class="amt">{{ $fmt($statement->owner_share) }}</td>
            </tr>
        @endif
        <tr class="sub">
            <td>{{ __('admin.owner_statements.fields.paid_to_date') }}</td>
            <td class="amt">({{ $fmt($statement->paid_to_date) }})</td>
        </tr>
        <tr class="net">
            <td>{{ __('admin.owner_statements.fields.outstanding') }}</td>
            <td class="amt">{{ $fmt($statement->outstanding()) }}</td>
        </tr>
    </table>

    <div class="footnote">
        {{ __('admin.owner_statements.pdf.note') }}
    </div>
</body>
</html>
