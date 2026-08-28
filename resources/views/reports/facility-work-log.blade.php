@php
    $isRtl = app()->getLocale() === 'ar';
    $t = fn ($k) => __("admin.preventive_maintenance.$k");
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $t('report.title') }}</title>
    <style>
        /* No @page rule: page geometry (size, margins, the band the running footer sits in)
           belongs to App\Support\Pdf\PdfDocument. A template that set its own margins here
           silently overrode mpdf's and left no room beneath the body, so the running footer
           carrying the document reference and `page x of y` rendered nowhere at all. */
        * { box-sizing: border-box; }
        body { color: #0F1419; font-size: 10pt; line-height: 1.5; margin: 0; }
        .header { border-bottom: 2px solid #0F766E; padding-bottom: 12px; margin-bottom: 16px; }
        .header table { width: 100%; border-collapse: collapse; }
        .brand-name { font-size: 20pt; font-weight: bold; }
        .doc-title { font-size: 15pt; color: #0F766E; text-align: {{ $isRtl ? 'left' : 'right' }}; }
        .doc-meta { text-align: {{ $isRtl ? 'left' : 'right' }}; font-size: 9pt; color: #6B6660; margin-top: 4px; }
        .summary { background: #F5F0E8; padding: 10px 14px; margin-bottom: 16px; }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary td { padding: 3px 8px; vertical-align: top; }
        .summary .k { color: #6B6660; font-size: 8.5pt; }
        .summary .v { font-weight: bold; font-size: 12pt; }
        .chips span { display: inline-block; background: #fff; border: 1px solid #E7E1D6; border-radius: 3px; padding: 1px 6px; margin: 2px; font-size: 8.5pt; }
        table.log { width: 100%; border-collapse: collapse; }
        table.log th { background: #0F766E; color: #fff; font-size: 8.5pt; padding: 6px 8px; text-align: {{ $isRtl ? 'right' : 'left' }}; }
        table.log td { padding: 6px 8px; border-bottom: 1px solid #E7E1D6; font-size: 9pt; }
        .st { font-size: 8pt; padding: 1px 6px; border-radius: 3px; }
        .st-done { background: #DCFCE7; color: #166534; }
        .st-cancelled { background: #F3F4F6; color: #4B5563; }
        .st-open, .st-in_progress { background: #DBEAFE; color: #1E40AF; }
        .empty { color: #8C8478; text-align: center; padding: 24px; }
        .footer { margin-top: 20px; font-size: 8pt; color: #8C8478; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td>
                    @include('partials.issuer-logo')
                    <div class="brand-name">{{ $issuerName }}</div>
                    <div style="color:#8C8478;font-size:9pt;">{{ $scopeLabel }}</div>
                </td>
                <td>
                    <div class="doc-title">{{ $t('report.title') }}</div>
                    <div class="doc-meta">
                        {{ $t('report.period') }}: {{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="summary">
        <table>
            <tr>
                <td style="width:20%;">
                    <div class="k">{{ $t('report.total') }}</div>
                    <div class="v">{{ $summary['total'] }}</div>
                </td>
                <td style="width:20%;">
                    <div class="k">{{ $t('report.done') }}</div>
                    <div class="v">{{ $summary['done'] }}</div>
                </td>
                <td class="chips" style="width:25%;">
                    {{-- The log covers preventive AND corrective work; state the split
                         rather than leave the reader to infer it from the references. --}}
                    <div class="k">{{ $t('report.by_type') }}</div>
                    @foreach ($summary['by_type'] as $type => $n)
                        <span>{{ $t("work_order_types.$type") }}: {{ $n }}</span>
                    @endforeach
                </td>
                <td class="chips" style="width:35%;">
                    <div class="k">{{ $t('report.by_category') }}</div>
                    @foreach ($summary['by_category'] as $cat => $n)
                        <span>{{ $t("categories.$cat") }}: {{ $n }}</span>
                    @endforeach
                </td>
            </tr>
        </table>
    </div>

    @if ($orders->isEmpty())
        <div class="empty">{{ $t('report.empty') }}</div>
    @else
        <table class="log">
            <thead>
                <tr>
                    <th>{{ $t('fields.reference') }}</th>
                    <th>{{ $t('fields.work_order_type') }}</th>
                    <th>{{ $t('fields.scheduled_for') }}</th>
                    <th>{{ $t('fields.title') }}</th>
                    <th>{{ $t('fields.category') }}</th>
                    <th>{{ $t('fields.property') }}</th>
                    <th>{{ $t('fields.status') }}</th>
                    <th>{{ $t('fields.completed_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $o)
                    <tr>
                        <td style="font-family:monospace;">{{ $o->reference }}</td>
                        <td>{{ $t("work_order_types.{$o->work_order_type}") }}</td>
                        <td>{{ optional($o->scheduled_for)->format('d/m/Y') }}</td>
                        <td>{{ $o->title }}</td>
                        <td>{{ $t("categories.{$o->category}") }}</td>
                        <td>{{ $o->asset?->name }}{{ $o->unit ? ' · '.$o->unit->code : '' }}</td>
                        <td><span class="st st-{{ $o->status }}">{{ $t("statuses.{$o->status}") }}</span></td>
                        <td>{{ optional($o->completed_at)->format('d/m/Y') ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">{{ $t('report.footer') }}</div>
</body>
</html>
