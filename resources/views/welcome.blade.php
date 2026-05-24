<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atriom · Egyptian Mall Operations</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('atriom-favicon.svg') }}">
    <meta name="theme-color" content="#0F766E">
    <style>
        :root {
            --bg: #0F1419;
            --surface: #1A2128;
            --cream: #F5F0E8;
            --muted: #94A3B8;
            --teal: #0F766E;
            --teal-bright: #14B8A6;
            --amber: #D97706;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: var(--bg);
            background-image:
                radial-gradient(at 20% 0%, rgba(15, 118, 110, 0.18) 0%, transparent 50%),
                radial-gradient(at 100% 100%, rgba(217, 119, 6, 0.10) 0%, transparent 50%);
            color: var(--cream);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 1040px;
            margin: 0 auto;
            padding: 4rem 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 3rem;
        }
        .brand img { height: 2.5rem; width: auto; filter: drop-shadow(0 2px 6px rgba(15, 118, 110, 0.25)); }
        .brand-divider {
            width: 1px;
            height: 1.75rem;
            background: rgba(245, 240, 232, 0.18);
        }
        .brand-text {
            font-size: 0.72rem;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--teal-bright);
            font-weight: 600;
        }
        h1 {
            font-size: 3rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            margin-bottom: 0.75rem;
            line-height: 1.1;
            background: linear-gradient(180deg, var(--cream) 0%, #C4B8A8 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        h1 .accent {
            color: var(--amber);
            -webkit-text-fill-color: var(--amber);
        }
        .lede {
            font-size: 1.2rem;
            color: var(--muted);
            max-width: 640px;
            margin-bottom: 3rem;
        }
        .features {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 2.5rem;
        }
        .feature {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.85rem;
            background: rgba(15, 118, 110, 0.10);
            border: 1px solid rgba(15, 118, 110, 0.35);
            border-radius: 999px;
            font-size: 0.78rem;
            color: var(--cream);
            font-weight: 500;
        }
        .feature::before {
            content: '';
            display: inline-block;
            width: 6px;
            height: 6px;
            background: var(--teal-bright);
            border-radius: 50%;
            box-shadow: 0 0 8px var(--teal-bright);
        }
        .panels {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 3rem;
        }
        @media (max-width: 720px) {
            .panels { grid-template-columns: 1fr; }
            h1 { font-size: 2.25rem; }
        }
        .panel {
            display: block;
            padding: 1.75rem 1.5rem;
            background: var(--surface);
            border: 1px solid rgba(245, 240, 232, 0.08);
            border-radius: 12px;
            text-decoration: none;
            color: var(--cream);
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--teal);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.2s ease;
        }
        .panel:hover {
            border-color: rgba(15, 118, 110, 0.5);
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35);
        }
        .panel:hover::before { transform: scaleX(1); }
        .panel-label {
            font-size: 0.68rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--teal-bright);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .panel-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--cream);
        }
        .panel-title::after { content: ' →'; color: var(--amber); }
        .panel-desc {
            font-size: 0.875rem;
            color: var(--muted);
            line-height: 1.55;
        }
        footer {
            padding: 1.5rem;
            text-align: center;
            font-size: 0.75rem;
            color: var(--muted);
            border-top: 1px solid rgba(245, 240, 232, 0.06);
        }
        footer a { color: var(--teal-bright); text-decoration: none; }
        footer a:hover { color: var(--amber); }
    </style>
</head>
<body>
    <div class="container">
        <div class="brand">
            <img src="{{ asset('images/atriom-logo.svg') }}" alt="Atriom">
            <div class="brand-divider"></div>
            <span class="brand-text">Egyptian Mall Operations</span>
        </div>

        <h1>Where every mall transaction<br><span class="accent">comes together.</span></h1>
        <p class="lede">
            Atriom is the operations platform built for Egyptian retail — leases, monthly billing,
            tenant sales declarations, CAM reconciliation, ETA e-invoicing. Three role-aware portals,
            one source of truth, native to how malls actually work here.
        </p>

        <div class="features">
            <span class="feature">Arabic-native</span>
            <span class="feature">ETA e-invoicing</span>
            <span class="feature">EGP &middot; EG VAT</span>
            <span class="feature">Tenant sales &amp; percentage rent</span>
            <span class="feature">CAM reconciliation</span>
            <span class="feature">Multi-property branding</span>
        </div>

        <div class="panels">
            <a class="panel" href="{{ url('/admin') }}">
                <div class="panel-label">For Operators</div>
                <div class="panel-title">Admin Console</div>
                <div class="panel-desc">Run the mall day to day — leases, billing, maintenance, ETA submissions.</div>
            </a>
            <a class="panel" href="{{ url('/owner') }}">
                <div class="panel-label">For Owners</div>
                <div class="panel-title">Owner Portal</div>
                <div class="panel-desc">Portfolio view across owned assets — performance, financials, oversight.</div>
            </a>
            <a class="panel" href="{{ url('/portal') }}">
                <div class="panel-label">For Tenants</div>
                <div class="panel-title">Tenant Portal</div>
                <div class="panel-desc">View invoices, pay, declare monthly sales, submit maintenance.</div>
            </a>
        </div>
    </div>

    <footer>
        Atriom &middot; Built for the Egyptian retail vertical &middot; <a href="{{ url('/admin/login') }}">Sign in</a>
    </footer>
</body>
</html>
