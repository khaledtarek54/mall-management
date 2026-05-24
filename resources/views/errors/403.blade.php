<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('errors.403.title') }} · Atriom</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('atriom-favicon.svg') }}">
    <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#09090B" media="(prefers-color-scheme: dark)">
    <style>
        :root {
            --bg: #FFFFFF; --ink: #18181B; --ink-muted: #52525B;
            --accent: #0F766E; --grad: rgba(15,118,110,0.06);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #09090B; --ink: #FAFAFA; --ink-muted: #A1A1AA;
                --accent: #14B8A6; --grad: rgba(20,184,166,0.10);
            }
        }
        body { margin:0; min-height:100vh; background: var(--bg); background-image: radial-gradient(at 50% 0%, var(--grad) 0%, transparent 60%); color: var(--ink); font-family: 'Inter', system-ui, sans-serif; display: flex; align-items: center; justify-content: center; -webkit-font-smoothing: antialiased; }
        .card { text-align: center; padding: 2rem; max-width: 480px; }
        .logo img { height: 2.25rem; margin-bottom: 1.5rem; }
        .code { font-size: 5.5rem; font-weight: 700; color: var(--accent); line-height: 1; letter-spacing: -0.03em; margin-bottom: 0.5rem; }
        h1 { font-size: 1.5rem; margin: 0 0 0.75rem; font-weight: 600; }
        p { color: var(--ink-muted); margin: 0 0 2rem; line-height: 1.55; }
        a { display: inline-flex; padding: 0.65rem 1.25rem; background: var(--ink); color: var(--bg); text-decoration: none; border-radius: 8px; font-weight: 600; transition: opacity 0.15s ease; }
        a:hover { opacity: 0.85; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo"><img src="{{ asset('images/atriom-logo.svg') }}" alt="Atriom"></div>
        <div class="code">403</div>
        <h1>{{ __('errors.403.title') }}</h1>
        <p>{{ __('errors.403.body') }}</p>
        <a href="{{ url('/') }}">{{ __('errors.actions.home') }}</a>
    </div>
</body>
</html>
