<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <title>{{ $app }} — test email</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Arial, sans-serif; color: #18181b; background: #fafafa; margin: 0; padding: 32px; }
        .card { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 32px; }
        .brand { font-weight: 700; font-size: 18px; letter-spacing: -0.02em; }
        .muted { color: #71717a; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin: 24px 0; }
        th, td { padding: 8px 0; text-align: left; border-bottom: 1px solid #f4f4f5; font-size: 14px; }
        th { color: #71717a; font-weight: 500; font-size: 12px; text-transform: uppercase; width: 40%; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">{{ $app }}</div>
    <p>Outbound email is working. This message was sent by <code>php artisan mail:test</code> — it carries no business data.</p>

    <table>
        <tr><th>Environment</th><td>{{ $env }}</td></tr>
        <tr><th>Application URL</th><td>{{ $url }}</td></tr>
        <tr><th>Mailer</th><td>{{ $mailer }}</td></tr>
        <tr><th>Sent at</th><td>{{ $sentAt }}</td></tr>
    </table>

    <p class="muted">If you did not expect this email, someone is testing the mail configuration of {{ $app }}.</p>
</div>
</body>
</html>
