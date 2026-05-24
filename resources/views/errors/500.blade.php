<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something went wrong — Mall Management</title>
    <link rel="icon" href="{{ asset('jawad-favicon.png') }}">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #1A1A1A;
            color: #F5F1EA;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
        }
        .card { text-align: center; padding: 2rem; max-width: 480px; }
        .code {
            font-size: 5rem;
            font-weight: 700;
            color: #C9A961;
            line-height: 1;
            letter-spacing: -0.02em;
            margin-bottom: 0.5rem;
        }
        h1 { font-size: 1.5rem; margin: 0 0 0.75rem; font-weight: 600; }
        p { color: #8C8478; margin: 0 0 2rem; line-height: 1.5; }
        .actions { display: inline-flex; gap: 0.75rem; flex-wrap: wrap; justify-content: center; }
        a {
            display: inline-flex;
            align-items: center;
            padding: 0.6rem 1.25rem;
            background: transparent;
            border: 1px solid rgba(201, 169, 97, 0.35);
            color: #F5F1EA;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.15s ease;
        }
        a:hover { border-color: #C9A961; background: rgba(201, 169, 97, 0.08); }
        a.primary { background: #C9A961; color: #1A1A1A; border-color: #C9A961; font-weight: 600; }
        a.primary:hover { background: #B59348; border-color: #B59348; }
    </style>
</head>
<body>
    <div class="card">
        <div class="code">500</div>
        <h1>{{ __('errors.500.title') }}</h1>
        <p>{{ __('errors.500.body') }}</p>
        <div class="actions">
            <a class="primary" href="{{ url('/') }}">{{ __('errors.actions.home') }}</a>
            <a href="{{ url()->previous() }}">{{ __('errors.actions.back') }}</a>
        </div>
    </div>
</body>
</html>
