<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied — Mall Management</title>
    <link rel="icon" href="{{ asset('jawad-favicon.png') }}">
    <style>
        body { margin: 0; min-height: 100vh; background: #1A1A1A; color: #F5F1EA; font-family: 'Inter', system-ui, sans-serif; display: flex; align-items: center; justify-content: center; -webkit-font-smoothing: antialiased; }
        .card { text-align: center; padding: 2rem; max-width: 480px; }
        .code { font-size: 5rem; font-weight: 700; color: #C9A961; line-height: 1; margin-bottom: 0.5rem; }
        h1 { font-size: 1.5rem; margin: 0 0 0.75rem; font-weight: 600; }
        p { color: #8C8478; margin: 0 0 2rem; line-height: 1.5; }
        a { display: inline-flex; padding: 0.6rem 1.25rem; background: #C9A961; color: #1A1A1A; text-decoration: none; border-radius: 6px; font-weight: 600; }
        a:hover { background: #B59348; }
    </style>
</head>
<body>
    <div class="card">
        <div class="code">403</div>
        <h1>{{ __('errors.403.title') }}</h1>
        <p>{{ __('errors.403.body') }}</p>
        <a href="{{ url('/') }}">{{ __('errors.actions.home') }}</a>
    </div>
</body>
</html>
