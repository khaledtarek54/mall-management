<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found · Atriom</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('atriom-favicon.svg') }}">
    <meta name="theme-color" content="#0F766E">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #0F1419;
            background-image: radial-gradient(at 50% 0%, rgba(15, 118, 110, 0.18) 0%, transparent 60%);
            color: #F5F0E8;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
        }
        .card { text-align: center; padding: 2rem; max-width: 480px; }
        .logo { display: inline-flex; margin-bottom: 1.5rem; }
        .logo img { height: 2.25rem; }
        .code {
            font-size: 5.5rem;
            font-weight: 700;
            color: #14B8A6;
            line-height: 1;
            letter-spacing: -0.03em;
            margin-bottom: 0.5rem;
            text-shadow: 0 4px 24px rgba(20, 184, 166, 0.25);
        }
        h1 { font-size: 1.5rem; margin: 0 0 0.75rem; font-weight: 600; }
        p { color: #94A3B8; margin: 0 0 2rem; line-height: 1.55; }
        .actions { display: inline-flex; gap: 0.75rem; flex-wrap: wrap; justify-content: center; }
        a {
            display: inline-flex;
            align-items: center;
            padding: 0.65rem 1.25rem;
            background: transparent;
            border: 1px solid rgba(15, 118, 110, 0.45);
            color: #F5F0E8;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.15s ease;
        }
        a:hover { border-color: #14B8A6; background: rgba(15, 118, 110, 0.12); }
        a.primary { background: #0F766E; color: #F5F0E8; border-color: #0F766E; font-weight: 600; }
        a.primary:hover { background: #14B8A6; border-color: #14B8A6; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo"><img src="{{ asset('images/atriom-logo.svg') }}" alt="Atriom"></div>
        <div class="code">404</div>
        <h1>{{ __('errors.404.title') }}</h1>
        <p>{{ __('errors.404.body') }}</p>
        <div class="actions">
            <a class="primary" href="{{ url('/') }}">{{ __('errors.actions.home') }}</a>
            <a href="{{ url('/admin') }}">{{ __('errors.actions.admin') }}</a>
        </div>
    </div>
</body>
</html>
