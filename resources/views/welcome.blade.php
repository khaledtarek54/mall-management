<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mall Management Platform</title>
    <link rel="icon" href="{{ asset('jawad-favicon.png') }}">
    <style>
        :root {
            --bg: #1A1A1A;
            --surface: #2A2A28;
            --cream: #F5F1EA;
            --muted: #8C8478;
            --gold: #C9A961;
            --gold-deep: #B59348;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: var(--bg);
            color: var(--cream);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 980px;
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
            gap: 1rem;
            margin-bottom: 2.5rem;
        }
        .brand img { height: 2.75rem; width: auto; }
        .brand-text {
            font-size: 0.75rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--gold);
            font-weight: 600;
        }
        h1 {
            font-size: 2.75rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 0.75rem;
            line-height: 1.15;
        }
        h1 .accent { color: var(--gold); }
        .lede {
            font-size: 1.15rem;
            color: var(--muted);
            max-width: 620px;
            margin-bottom: 3rem;
        }
        .panels {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 3rem;
        }
        @media (max-width: 720px) {
            .panels { grid-template-columns: 1fr; }
            h1 { font-size: 2rem; }
        }
        .panel {
            display: block;
            padding: 1.5rem 1.5rem 1.75rem;
            background: var(--surface);
            border: 1px solid rgba(201, 169, 97, 0.18);
            border-radius: 10px;
            text-decoration: none;
            color: var(--cream);
            transition: all 0.15s ease;
        }
        .panel:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }
        .panel-label {
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 0.4rem;
            font-weight: 600;
        }
        .panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
        }
        .panel-desc {
            font-size: 0.875rem;
            color: var(--muted);
            line-height: 1.5;
        }
        .features {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }
        .feature {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            background: rgba(201, 169, 97, 0.08);
            border: 1px solid rgba(201, 169, 97, 0.25);
            border-radius: 6px;
            font-size: 0.78rem;
            color: var(--cream);
        }
        .feature::before {
            content: '';
            display: inline-block;
            width: 6px;
            height: 6px;
            background: var(--gold);
            border-radius: 50%;
        }
        footer {
            padding: 1.5rem;
            text-align: center;
            font-size: 0.75rem;
            color: var(--muted);
            border-top: 1px solid rgba(201, 169, 97, 0.12);
        }
        footer a { color: var(--gold); text-decoration: none; }
        footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="brand">
            <img src="{{ asset('images/jawad-logo.png') }}" alt="Jawad Developments">
            <span class="brand-text">Mall Management Platform</span>
        </div>

        <h1>Egyptian retail operations,<br><span class="accent">end to end.</span></h1>
        <p class="lede">
            A specialized mall-operations platform built for Egypt — leases, monthly billing,
            tenant sales declarations, CAM reconciliation, and ETA e-invoicing. Three role-aware
            portals, one source of truth.
        </p>

        <div class="features">
            <span class="feature">Arabic-native</span>
            <span class="feature">ETA e-invoicing</span>
            <span class="feature">EGP / EG VAT</span>
            <span class="feature">Tenant sales + percentage rent</span>
            <span class="feature">CAM reconciliation</span>
            <span class="feature">Multi-property branding</span>
        </div>

        <div class="panels">
            <a class="panel" href="{{ url('/admin') }}">
                <div class="panel-label">For Operators</div>
                <div class="panel-title">Admin Panel →</div>
                <div class="panel-desc">Run the mall day to day — leases, billing, maintenance, ETA submissions.</div>
            </a>
            <a class="panel" href="{{ url('/owner') }}">
                <div class="panel-label">For Owners</div>
                <div class="panel-title">Owner Portal →</div>
                <div class="panel-desc">Portfolio view across owned assets — performance, financials, oversight.</div>
            </a>
            <a class="panel" href="{{ url('/portal') }}">
                <div class="panel-label">For Tenants</div>
                <div class="panel-title">Tenant Portal →</div>
                <div class="panel-desc">View invoices, pay, declare monthly sales, submit maintenance.</div>
            </a>
        </div>
    </div>

    <footer>
        Built for the Egyptian mall vertical · <a href="{{ url('/admin/login') }}">Sign in</a>
    </footer>
</body>
</html>
