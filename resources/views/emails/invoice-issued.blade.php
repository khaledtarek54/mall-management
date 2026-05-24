@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('admin.email.invoice_issued_subject', ['number' => $invoice->number]) }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Arial, sans-serif; color: #18181b; background: #fafafa; margin: 0; padding: 32px; }
        .card { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 32px; }
        .brand { font-weight: 700; font-size: 18px; letter-spacing: -0.02em; }
        .muted { color: #71717a; font-size: 13px; }
        .amount { font-size: 28px; font-weight: 600; margin: 16px 0; }
        table { width: 100%; border-collapse: collapse; margin: 24px 0; }
        th, td { padding: 8px 0; text-align: {{ $dir === 'rtl' ? 'right' : 'left' }}; border-bottom: 1px solid #f4f4f5; }
        th { color: #71717a; font-weight: 500; font-size: 12px; text-transform: uppercase; }
        .btn { display: inline-block; padding: 10px 18px; background: #18181b; color: #fff; text-decoration: none; border-radius: 8px; font-size: 14px; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">Atriom</div>
    <p>{{ __('admin.email.greeting', ['name' => $tenant->name]) }}</p>
    <p>{{ __('admin.email.invoice_issued_body', ['number' => $invoice->number, 'due_date' => $invoice->due_date->format('d M Y')]) }}</p>

    <div class="amount">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency }}</div>

    <table>
        <tr><th>{{ __('admin.tables.invoice.number') }}</th><td>{{ $invoice->number }}</td></tr>
        <tr><th>{{ __('admin.fields.period') }}</th><td>{{ $invoice->period_start->format('d M Y') }} → {{ $invoice->period_end->format('d M Y') }}</td></tr>
        <tr><th>{{ __('admin.tables.invoice.due_date') }}</th><td>{{ $invoice->due_date->format('d M Y') }}</td></tr>
        <tr><th>{{ __('admin.tables.invoice.balance') }}</th><td>{{ number_format((float) $invoice->balance, 2) }} {{ $invoice->currency }}</td></tr>
    </table>

    <p class="muted">{{ __('admin.email.footer') }}</p>
</div>
</body>
</html>
