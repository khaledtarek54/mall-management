@php $rtl = app()->getLocale() === 'ar'; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('pay.title', ['number' => $invoice->number]) }} · Atriom</title>
    <style>
        :root { --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --brand:#0f766e; --bg:#f1f5f9; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--ink); }
        .wrap { min-height:100dvh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card { background:#fff; width:100%; max-width:420px; border-radius:18px; box-shadow:0 10px 40px rgba(2,6,23,.08); overflow:hidden; }
        .head { padding:22px 24px; border-bottom:1px solid var(--line); display:flex; align-items:center; gap:10px; }
        .brand { font-weight:800; letter-spacing:.3px; color:var(--brand); font-size:18px; }
        .body { padding:24px; }
        .label { font-size:13px; color:var(--muted); }
        .amount { font-size:40px; font-weight:800; margin:4px 0 2px; }
        .cur { font-size:16px; color:var(--muted); font-weight:600; }
        .meta { margin:18px 0 6px; border-top:1px dashed var(--line); padding-top:16px; }
        .row { display:flex; justify-content:space-between; font-size:14px; padding:6px 0; }
        .row .k { color:var(--muted); }
        .btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; border:0; border-radius:12px; padding:15px; font-size:16px; font-weight:700; cursor:pointer; margin-top:14px; }
        .btn-pay { background:var(--brand); color:#fff; }
        .btn-apple { background:#000; color:#fff; }
        .err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; border-radius:10px; padding:10px 12px; font-size:14px; margin-bottom:14px; }
        .foot { text-align:center; color:var(--muted); font-size:12px; padding:16px 24px 22px; }
        form { margin:0; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head"><span class="brand">Atriom</span><span style="color:var(--muted);font-size:13px;">{{ __('pay.secure_payment') }}</span></div>
        <div class="body">
            @if (session('error'))
                <div class="err">{{ session('error') }}</div>
            @endif

            <div class="label">{{ __('pay.amount_due') }}</div>
            <div class="amount">{{ number_format((float) $invoice->balance, 2) }} <span class="cur">{{ $invoice->currency ?? 'EGP' }}</span></div>

            <div class="meta">
                <div class="row"><span class="k">{{ __('pay.invoice') }}</span><span>{{ $invoice->number }}</span></div>
                @if ($invoice->tenant)
                    <div class="row"><span class="k">{{ __('pay.billed_to') }}</span><span>{{ $invoice->tenant->name }}</span></div>
                @endif
                @if ($invoice->period_start && $invoice->period_end)
                    <div class="row"><span class="k">{{ __('pay.period') }}</span><span>{{ $invoice->period_start->format('d M') }} – {{ $invoice->period_end->format('d M Y') }}</span></div>
                @endif
                @if ($invoice->due_date)
                    <div class="row"><span class="k">{{ __('pay.due') }}</span><span>{{ $invoice->due_date->format('d M Y') }}</span></div>
                @endif
            </div>

            @if ($paymentEnabled)
                <form method="POST" action="{{ route('pay.start', ['token' => $token]) }}">
                    @csrf
                    <button class="btn btn-pay" type="submit">{{ __('pay.pay_with_card') }}</button>
                </form>

                @if ($applePayEnabled)
                    <form method="POST" action="{{ route('pay.start', ['token' => $token]) }}">
                        @csrf
                        <input type="hidden" name="method" value="apple_pay">
                        <button class="btn btn-apple" type="submit">&#63743;&nbsp; Pay</button>
                    </form>
                @endif
            @else
                <div class="err" style="margin-top:14px;">{{ __('pay.unavailable') }}</div>
            @endif
        </div>
        <div class="foot">{{ __('pay.secured_by') }} · {{ __('pay.redirect_note') }}</div>
    </div>
</div>
</body>
</html>
