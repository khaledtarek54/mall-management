@php
    $map = [
        'paid'       => ['#0f766e', '#ecfdf5', '✓', 'Payment successful', 'Your payment has been received. Thank you.'],
        'failed'     => ['#b91c1c', '#fef2f2', '✕', 'Payment failed', 'The payment did not go through. You can try again.'],
        'processing' => ['#b45309', '#fffbeb', '…', 'Processing payment', 'We are confirming your payment. This page will update automatically.'],
        'unpaid'     => ['#475569', '#f1f5f9', '•', 'Invoice not paid', 'This invoice has not been paid yet.'],
    ];
    [$color, $bg, $icon, $title, $msg] = $map[$state] ?? $map['unpaid'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @if ($state === 'processing')
        <meta http-equiv="refresh" content="4">
    @endif
    <title>{{ $title }} · Atriom</title>
    <style>
        * { box-sizing:border-box; }
        body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f1f5f9; color:#0f172a; }
        .wrap { min-height:100dvh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card { background:#fff; width:100%; max-width:420px; border-radius:18px; box-shadow:0 10px 40px rgba(2,6,23,.08); overflow:hidden; text-align:center; }
        .badge { width:74px; height:74px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:38px; font-weight:800; margin:28px auto 6px; color:{{ $color }}; background:{{ $bg }}; }
        .title { font-size:22px; font-weight:800; margin:8px 24px 2px; }
        .msg { color:#64748b; font-size:14px; margin:0 28px 8px; }
        .amount { font-size:15px; color:#0f172a; margin:14px 0 2px; font-weight:700; }
        .meta { color:#94a3b8; font-size:13px; margin-bottom:18px; }
        .btn { display:inline-block; text-decoration:none; border-radius:12px; padding:13px 18px; font-size:15px; font-weight:700; margin:6px 24px 8px; }
        .btn-app { background:#0f766e; color:#fff; }
        .btn-retry { background:#0f172a; color:#fff; }
        .foot { color:#94a3b8; font-size:12px; padding:8px 24px 24px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="badge">{{ $icon }}</div>
        <div class="title">{{ $title }}</div>
        <p class="msg">{{ $msg }}</p>

        <div class="amount">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency ?? 'EGP' }}</div>
        <div class="meta">Invoice {{ $invoice->number }}@if ($invoice->tenant) · {{ $invoice->tenant->name }}@endif</div>

        @if ($state === 'paid' && $appDeepLink)
            <div><a class="btn btn-app" href="{{ $appDeepLink }}">Open the app to confirm</a></div>
        @endif

        @if ($state === 'failed' && $invoice->isPayable())
            <div><a class="btn btn-retry" href="{{ route('pay.show', ['token' => $token]) }}">Try again</a></div>
        @endif

        <div class="foot">Secured by Paymob</div>
    </div>
</div>
</body>
</html>
