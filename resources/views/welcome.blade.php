{{--
    The public landing page.

    Three things it must keep being, because each was wrong before and none of them is visible from
    a screenshot in one language:

    1. BILINGUAL AND BIDIRECTIONAL. The panel, both portals and the API are Arabic-native; a page
       that opens the product in English only says the opposite. Every string comes from
       `lang/{en,ar}/landing.php`, and the layout is written in CSS LOGICAL PROPERTIES
       (margin-inline, inset-inline, border-inline-start, text-align: start) so RTL needs no
       second stylesheet and no plugin — the same choice the visual handbook made.

    2. TRUE. Every number is read from `App\Support\LandingFacts`, which asks the registries that
       own them. Nothing here is a count somebody typed.

    3. CREDENTIAL-FREE. This page is public. It links to the sign-in screens and names no account,
       no password and no environment. The demo logins live in CLAUDE.md and the docs, which are
       for people who already have the repository.

    Self-contained on purpose: no build step, no external font, no CDN. It is the one page that has
    to render when the asset pipeline has not run.
--}}
@php
    use App\Support\LandingFacts;

    $facts = LandingFacts::all();
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
    $otherLocale = $isRtl ? 'en' : 'ar';

    /*
     * A language names itself the same way in every language, so an ENDONYM is data, not prose —
     * the same reasoning that puts `locale` in `ActivityVocabulary::VERBATIM_VALUES`, and what the
     * panel's own switcher already does (`filament/language-switch.blade.php` hardcodes EN /
     * عربي). Putting it in the catalogue instead means the string "English" has to sit in
     * `lang/ar`, which `TranslationKeyConformanceTest` correctly refuses as English in an Arabic
     * file — a gate worth keeping sharp rather than exempting.
     */
    $endonyms = ['en' => 'English', 'ar' => 'العربية'];
    $otherLanguage = $endonyms[$otherLocale] ?? strtoupper($otherLocale);

    // The 14 sidebar groups, in the order App\Support\Navigation renders them, each paired with
    // the icon used below. The order is the operator's mental model of the system, so the page
    // teaches the same shape the panel does.
    $capabilities = [
        'leasing' => 'building',
        'receivables' => 'receipt',
        'recoveries' => 'recycle',
        'payables' => 'truck',
        'owners' => 'users',
        'general_ledger' => 'book',
        'reports' => 'chart',
        'operations' => 'clipboard',
        'facility' => 'wrench',
        'inventory_assets' => 'cube',
        'hr_payroll' => 'badge',
        'marketing' => 'megaphone',
        'setup' => 'sliders',
        'administration' => 'shield',
    ];

    $spine = ['lease', 'billing', 'recovery', 'collection', 'ledger', 'statement'];

    $egypt = [
        'arabic' => 'language',
        'tax' => 'percent',
        'cheques' => 'cheque',
        'owners' => 'key',
        'custody' => 'wallet',
        'calendar' => 'calendar',
        'payroll' => 'badge',
        'payments' => 'card',
    ];

    $engineering = [
        'never_delete' => 'lock',
        'isolation' => 'layers',
        'ledger' => 'book',
        'audit' => 'history',
        'periods' => 'calendar',
        'gates' => 'check',
    ];

    $automation = ['billing', 'recoveries', 'leases', 'facility', 'compliance', 'books', 'housekeeping'];

    $documents = ['invoice', 'statement', 'cam', 'owner', 'financials', 'purchase', 'payslip'];

    $stats = [
        ['value' => $facts['documented_modules'], 'key' => 'modules'],
        ['value' => $facts['gl_sources'], 'key' => 'gl_sources'],
        ['value' => $facts['screens'], 'key' => 'screens'],
        ['value' => $facts['reports'], 'key' => 'reports'],
        ['value' => $facts['roles'], 'key' => 'roles'],
        ['value' => $facts['surfaces'], 'key' => 'surfaces'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('landing.meta.title') }}</title>
    <meta name="description" content="{{ __('landing.meta.description') }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('atriom-favicon.svg') }}">
    <link rel="alternate" hreflang="{{ $otherLocale }}" href="{{ url('/locale/'.$otherLocale) }}">
    <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#09090B" media="(prefers-color-scheme: dark)">
    <meta property="og:title" content="{{ __('landing.meta.title') }}">
    <meta property="og:description" content="{{ __('landing.meta.description') }}">
    <meta property="og:type" content="website">
    <meta name="robots" content="index, follow">
    <style>
        /* ── Tokens ─────────────────────────────────────────────────────────────────────────── */
        :root {
            --bg: #FFFFFF;
            --bg-alt: #FAFAFA;
            --surface: #FFFFFF;
            --surface-2: #F4F4F5;
            --border: rgba(9, 9, 11, 0.09);
            --border-strong: rgba(9, 9, 11, 0.16);
            --ink: #18181B;
            --ink-muted: #52525B;
            --ink-subtle: #71717A;
            --teal: #0F766E;
            --teal-soft: rgba(15, 118, 110, 0.10);
            --amber: #B45309;
            --amber-soft: rgba(180, 83, 9, 0.10);
            --shadow: 0 1px 2px rgba(9, 9, 11, 0.04), 0 8px 24px rgba(9, 9, 11, 0.05);
            --shadow-lift: 0 2px 4px rgba(9, 9, 11, 0.05), 0 18px 40px rgba(9, 9, 11, 0.10);
            --glow-1: rgba(15, 118, 110, 0.10);
            --glow-2: rgba(180, 83, 9, 0.07);
            --nav-bg: rgba(255, 255, 255, 0.82);
            --radius: 14px;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #09090B;
                --bg-alt: #0C0C0F;
                --surface: #131316;
                --surface-2: #1C1C20;
                --border: rgba(255, 255, 255, 0.09);
                --border-strong: rgba(255, 255, 255, 0.18);
                --ink: #FAFAFA;
                --ink-muted: #A1A1AA;
                --ink-subtle: #7E7E88;
                --teal: #2DD4BF;
                --teal-soft: rgba(45, 212, 191, 0.12);
                --amber: #FBBF24;
                --amber-soft: rgba(251, 191, 36, 0.12);
                --shadow: 0 1px 2px rgba(0, 0, 0, 0.5), 0 8px 24px rgba(0, 0, 0, 0.35);
                --shadow-lift: 0 2px 4px rgba(0, 0, 0, 0.5), 0 18px 40px rgba(0, 0, 0, 0.55);
                --glow-1: rgba(45, 212, 191, 0.11);
                --glow-2: rgba(251, 191, 36, 0.06);
                --nav-bg: rgba(9, 9, 11, 0.80);
            }
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; scroll-padding-top: 5.5rem; -webkit-text-size-adjust: 100%; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }

        body {
            background: var(--bg);
            color: var(--ink);
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', 'Noto Sans Arabic', 'Geeza Pro', 'Segoe UI Historic', Arial, sans-serif;
            font-size: 16px;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }
        html[dir="rtl"] body { line-height: 1.85; }

        a { color: inherit; text-decoration: none; }
        img, svg { max-width: 100%; }
        ul { list-style: none; }

        :focus-visible {
            outline: 2px solid var(--teal);
            outline-offset: 3px;
            border-radius: 4px;
        }

        .skip {
            position: absolute;
            inset-inline-start: -9999px;
            top: 0.5rem;
            z-index: 100;
            padding: 0.6rem 1rem;
            background: var(--surface);
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            font-weight: 600;
        }
        .skip:focus { inset-inline-start: 0.75rem; }

        /* ── Layout ─────────────────────────────────────────────────────────────────────────── */
        .wrap { width: min(100% - 2.5rem, 1140px); margin-inline: auto; }
        section { padding-block: clamp(4rem, 9vw, 7rem); }
        .band { background: var(--bg-alt); border-block: 1px solid var(--border); }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--teal);
        }
        html[dir="rtl"] .eyebrow { letter-spacing: normal; font-size: 0.78rem; }
        .eyebrow::before {
            content: '';
            width: 1.5rem;
            height: 1px;
            background: currentColor;
            opacity: 0.5;
        }

        h1, h2, h3 { line-height: 1.2; letter-spacing: -0.02em; font-weight: 700; }
        html[dir="rtl"] h1, html[dir="rtl"] h2, html[dir="rtl"] h3 { letter-spacing: normal; line-height: 1.45; }

        .section-head { max-width: 44rem; margin-block-end: clamp(2.5rem, 5vw, 3.5rem); }
        .section-head h2 { font-size: clamp(1.75rem, 3.6vw, 2.5rem); margin-block: 0.9rem 0.85rem; }
        .section-head p { color: var(--ink-muted); font-size: 1.05rem; }

        /* ── Nav ────────────────────────────────────────────────────────────────────────────── */
        header.nav {
            position: sticky;
            top: 0;
            z-index: 50;
            background: var(--nav-bg);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-block-end: 1px solid transparent;
            transition: border-color 0.2s ease;
        }
        header.nav[data-scrolled="true"] { border-block-end-color: var(--border); }
        .nav-inner {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            height: 4.25rem;
        }
        .nav-logo { display: flex; align-items: center; gap: 0.6rem; flex-shrink: 0; }
        .nav-logo img { height: 2rem; width: auto; }
        .nav-links { display: flex; gap: 0.35rem; margin-inline-start: auto; }
        .nav-links a {
            padding: 0.45rem 0.8rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--ink-muted);
            transition: color 0.15s ease, background 0.15s ease;
        }
        .nav-links a:hover { color: var(--ink); background: var(--surface-2); }
        .nav-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
        @media (max-width: 900px) {
            .nav-links { display: none; }
            .nav-actions { margin-inline-start: auto; }
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.6rem 1.1rem;
            border-radius: 9px;
            border: 1px solid transparent;
            font-size: 0.9rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            white-space: nowrap;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, border-color 0.15s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }
        .btn-primary { background: var(--teal); color: #FFFFFF; box-shadow: var(--shadow); }
        @media (prefers-color-scheme: dark) { .btn-primary { color: #06201D; } }
        .btn-primary:hover { box-shadow: var(--shadow-lift); }
        .btn-ghost { border-color: var(--border-strong); color: var(--ink); background: var(--surface); }
        .btn-ghost:hover { background: var(--surface-2); }
        .btn-lg { padding: 0.8rem 1.5rem; font-size: 0.98rem; }
        .btn .arrow { transition: transform 0.15s ease; }
        .btn:hover .arrow { transform: translateX(3px); }
        html[dir="rtl"] .btn:hover .arrow { transform: translateX(-3px); }
        html[dir="rtl"] .arrow { transform: scaleX(-1); }
        html[dir="rtl"] .btn:hover .arrow { transform: scaleX(-1) translateX(3px); }

        .lang {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 0.8rem;
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--ink-muted);
            transition: color 0.15s ease, border-color 0.15s ease;
        }
        .lang:hover { color: var(--ink); border-color: var(--border-strong); }

        /* ── Hero ───────────────────────────────────────────────────────────────────────────── */
        .hero {
            position: relative;
            padding-block: clamp(4rem, 10vw, 8rem) clamp(3rem, 7vw, 5rem);
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(60rem 32rem at 12% -10%, var(--glow-1) 0%, transparent 60%),
                radial-gradient(48rem 28rem at 100% 10%, var(--glow-2) 0%, transparent 62%);
            pointer-events: none;
        }
        .hero-grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(to right, var(--border) 1px, transparent 1px),
                linear-gradient(to bottom, var(--border) 1px, transparent 1px);
            background-size: 64px 64px;
            mask-image: radial-gradient(48rem 30rem at 50% 0%, #000 0%, transparent 75%);
            -webkit-mask-image: radial-gradient(48rem 30rem at 50% 0%, #000 0%, transparent 75%);
            opacity: 0.7;
            pointer-events: none;
        }
        .hero .wrap { position: relative; }
        .hero-layout {
            display: grid;
            gap: clamp(2.5rem, 5vw, 4rem);
            align-items: center;
        }
        @media (min-width: 1040px) {
            .hero-layout { grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr); }
        }
        .hero h1 {
            font-size: clamp(2.3rem, 6.2vw, 4rem);
            margin-block: 1.1rem 1.25rem;
            max-width: 18ch;
        }
        html[dir="rtl"] .hero h1 { max-width: 22ch; }
        .hero h1 .accent {
            color: var(--amber);
            display: block;
        }
        .hero .lede {
            font-size: clamp(1.02rem, 1.6vw, 1.2rem);
            color: var(--ink-muted);
            max-width: 46rem;
            margin-block-end: 2rem;
        }
        .hero-cta { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-block-end: 2rem; }
        .hero-trust {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.86rem;
            color: var(--ink-subtle);
        }
        .pulse {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--teal);
            box-shadow: 0 0 0 0 var(--teal-soft);
            animation: pulse 2.4s infinite;
            flex-shrink: 0;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 var(--teal-soft); }
            70% { box-shadow: 0 0 0 10px transparent; }
            100% { box-shadow: 0 0 0 0 transparent; }
        }
        @media (prefers-reduced-motion: reduce) { .pulse { animation: none; } }

        /* ── Hero illustration ──────────────────────────────────────────────────────────────
           A worked example, not a decorative mock: the rent line carries no VAT and the service
           charge does, which is the rule this market runs on and the one a ported system gets
           wrong. It is real text rather than an image, so it translates, reflows and reads in a
           screen reader. Hidden below the breakpoint where it would stack under the copy and push
           the whole page down before anything has been said. */
        .figure { display: none; }
        @media (min-width: 1040px) { .figure { display: block; } }

        .doc {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lift);
            overflow: hidden;
        }
        .doc-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.95rem 1.2rem;
            background: var(--surface-2);
            border-block-end: 1px solid var(--border);
        }
        .doc-head .kind { font-size: 0.78rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-muted); }
        html[dir="rtl"] .doc-head .kind { letter-spacing: normal; font-size: 0.86rem; }
        .doc-head .ref { font-size: 0.76rem; color: var(--ink-subtle); font-variant-numeric: tabular-nums; }
        .doc-body { padding: 0.5rem 1.2rem 1rem; }
        .row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            padding-block: 0.5rem;
            font-size: 0.86rem;
            border-block-end: 1px solid var(--border);
        }
        .row:last-child { border-block-end: 0; }
        .row .name { color: var(--ink-muted); }
        .row .amt { font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--ink); }
        .row.total { padding-block-start: 0.75rem; font-weight: 700; }
        .row.total .name, .row.total .amt { color: var(--ink); font-size: 0.95rem; }
        .tag {
            display: inline-block;
            margin-inline-start: 0.45rem;
            padding: 0.05rem 0.4rem;
            border-radius: 5px;
            background: var(--amber-soft);
            color: var(--amber);
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            vertical-align: 0.08em;
        }
        html[dir="rtl"] .tag { letter-spacing: normal; text-transform: none; font-size: 0.72rem; }
        .side { display: inline-block; width: 1.6rem; font-weight: 700; color: var(--teal); font-size: 0.78rem; }
        html[dir="rtl"] .side { width: auto; margin-inline-end: 0.5rem; }
        .posts {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin-block: 1.35rem;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--ink-subtle);
        }
        .posts span {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 0.25rem 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .posts svg { width: 0.85rem; height: 0.85rem; transform: rotate(90deg); }
        .balanced {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.65rem 1.2rem;
            border-block-start: 1px solid var(--border);
            background: var(--teal-soft);
            color: var(--teal);
            font-size: 0.78rem;
            font-weight: 700;
        }
        .balanced svg { width: 0.9rem; height: 0.9rem; }

        /* ── Stats ──────────────────────────────────────────────────────────────────────────── */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr));
            gap: 1px;
            background: var(--border);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .stat { background: var(--surface); padding: 1.4rem 1.25rem; }
        .stat-value {
            font-size: clamp(1.8rem, 3vw, 2.4rem);
            font-weight: 700;
            letter-spacing: -0.03em;
            color: var(--teal);
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
        }
        .stat-label {
            font-size: 0.8rem;
            color: var(--ink-muted);
            margin-block-start: 0.4rem;
            text-wrap: balance;
        }
        .stats-note {
            margin-block-start: 0.9rem;
            font-size: 0.8rem;
            color: var(--ink-subtle);
            text-align: center;
        }

        /* ── Spine ──────────────────────────────────────────────────────────────────────────── */
        .spine { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); }
        @media (min-width: 820px) { .spine { grid-template-columns: repeat(3, 1fr); } }
        .step {
            position: relative;
            padding: 1.5rem 1.4rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
        }
        .step-n {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.9rem; height: 1.9rem;
            border-radius: 7px;
            background: var(--teal-soft);
            color: var(--teal);
            font-size: 0.82rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            margin-block-end: 0.85rem;
        }
        .step h3 { font-size: 1.05rem; margin-block-end: 0.45rem; }
        .step p { font-size: 0.9rem; color: var(--ink-muted); }
        .spine-note {
            margin-block-start: 1.5rem;
            padding: 1.15rem 1.35rem;
            border-radius: var(--radius);
            background: var(--amber-soft);
            border-inline-start: 3px solid var(--amber);
            font-size: 0.92rem;
            color: var(--ink-muted);
        }

        /* ── Card grids ─────────────────────────────────────────────────────────────────────── */
        .grid { display: grid; gap: 1rem; }
        .grid-3 { grid-template-columns: repeat(auto-fit, minmax(17.5rem, 1fr)); }
        .grid-4 { grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); }

        .card {
            padding: 1.6rem 1.45rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            transition: border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card:hover { border-color: var(--border-strong); transform: translateY(-2px); box-shadow: var(--shadow); }
        .card h3 { font-size: 1.02rem; margin-block-end: 0.5rem; }
        .card p { font-size: 0.89rem; color: var(--ink-muted); }
        .card .ico {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.35rem; height: 2.35rem;
            border-radius: 9px;
            background: var(--teal-soft);
            color: var(--teal);
            margin-block-end: 0.95rem;
        }
        .card .ico svg { width: 1.15rem; height: 1.15rem; }
        .card.amber .ico { background: var(--amber-soft); color: var(--amber); }

        /* ── Surfaces ───────────────────────────────────────────────────────────────────────── */
        .surface-card {
            display: flex;
            flex-direction: column;
            padding: 1.75rem 1.55rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            position: relative;
            overflow: hidden;
            transition: border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        .surface-card::before {
            content: '';
            position: absolute;
            inset-block-start: 0;
            inset-inline: 0;
            height: 2px;
            background: linear-gradient(to right, var(--teal), var(--amber));
            transform: scaleX(0);
            transform-origin: inline-start;
            transition: transform 0.25s ease;
        }
        .surface-card:hover { border-color: var(--border-strong); transform: translateY(-3px); box-shadow: var(--shadow-lift); }
        .surface-card:hover::before { transform: scaleX(1); }
        .surface-card .label {
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--ink-subtle);
            margin-block-end: 0.55rem;
        }
        html[dir="rtl"] .surface-card .label { letter-spacing: normal; font-size: 0.76rem; }
        .surface-card h3 { font-size: 1.2rem; margin-block-end: 0.55rem; }
        .surface-card p { font-size: 0.89rem; color: var(--ink-muted); flex: 1; }
        .surface-card .go {
            margin-block-start: 1.1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.87rem;
            font-weight: 600;
            color: var(--teal);
        }
        .surface-card .go svg { width: 0.95rem; height: 0.95rem; transition: transform 0.15s ease; }
        .surface-card:hover .go svg { transform: translateX(3px); }
        html[dir="rtl"] .surface-card .go svg { transform: scaleX(-1); }
        html[dir="rtl"] .surface-card:hover .go svg { transform: scaleX(-1) translateX(3px); }
        .surface-card.static { cursor: default; }
        .surface-card.static .go { color: var(--ink-subtle); }

        /* ── Lists ──────────────────────────────────────────────────────────────────────────── */
        .ticks { display: grid; gap: 0.7rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); }
        .tick {
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            padding: 0.9rem 1.1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 0.9rem;
            color: var(--ink-muted);
        }
        .tick svg { width: 1.05rem; height: 1.05rem; color: var(--teal); flex-shrink: 0; margin-block-start: 0.2rem; }

        .chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.9rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 0.84rem;
            color: var(--ink-muted);
        }
        .chip svg { width: 0.9rem; height: 0.9rem; color: var(--amber); }

        /* ── CTA ────────────────────────────────────────────────────────────────────────────── */
        .cta-box {
            position: relative;
            padding: clamp(2.5rem, 6vw, 4rem);
            border-radius: 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            overflow: hidden;
            text-align: center;
        }
        .cta-box::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(40rem 20rem at 20% 0%, var(--glow-1) 0%, transparent 60%),
                radial-gradient(36rem 20rem at 90% 100%, var(--glow-2) 0%, transparent 60%);
            pointer-events: none;
        }
        .cta-box > * { position: relative; }
        .cta-box h2 { font-size: clamp(1.6rem, 3.2vw, 2.2rem); margin-block-end: 0.75rem; }
        .cta-box p { color: var(--ink-muted); margin-block-end: 1.75rem; }
        .cta-actions { display: flex; flex-wrap: wrap; gap: 0.7rem; justify-content: center; }

        /* ── Footer ─────────────────────────────────────────────────────────────────────────── */
        footer {
            border-block-start: 1px solid var(--border);
            background: var(--bg-alt);
            padding-block: 3rem 2rem;
        }
        .foot-grid {
            display: grid;
            gap: 2rem;
            grid-template-columns: minmax(14rem, 1.6fr) repeat(auto-fit, minmax(9rem, 1fr));
            margin-block-end: 2.5rem;
        }
        .foot-brand img { height: 1.9rem; width: auto; margin-block-end: 0.85rem; }
        .foot-brand p { font-size: 0.87rem; color: var(--ink-muted); max-width: 22rem; }
        .foot-col h4 {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--ink-subtle);
            margin-block-end: 0.85rem;
        }
        html[dir="rtl"] .foot-col h4 { letter-spacing: normal; font-size: 0.78rem; }
        .foot-col li { margin-block-end: 0.5rem; }
        .foot-col a { font-size: 0.88rem; color: var(--ink-muted); transition: color 0.15s ease; }
        .foot-col a:hover { color: var(--teal); }
        .foot-bottom {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.5rem;
            align-items: center;
            justify-content: space-between;
            padding-block-start: 1.75rem;
            border-block-start: 1px solid var(--border);
            font-size: 0.8rem;
            color: var(--ink-subtle);
        }
        .powered strong { color: var(--teal); font-weight: 700; letter-spacing: 0.04em; }

        /* ── Reveal ─────────────────────────────────────────────────────────────────────────── */
        .reveal { opacity: 0; transform: translateY(14px); transition: opacity 0.55s ease, transform 0.55s ease; }
        .reveal.in { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) {
            .reveal { opacity: 1; transform: none; transition: none; }
            .btn:hover, .card:hover, .surface-card:hover { transform: none; }
        }
        .no-js .reveal { opacity: 1; transform: none; }

        .sr-only {
            position: absolute;
            width: 1px; height: 1px;
            padding: 0; margin: -1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
            border: 0;
        }
    </style>
</head>
<body class="no-js">
<a class="skip" href="#main">{{ __('landing.nav.skip') }}</a>

{{-- One sprite, referenced by <use> — keeps the markup readable and the icon set in one place. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="ic-building" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V6l7-3 7 3v15M9 9h1m4 0h1M9 13h1m4 0h1M9 17h6"/></symbol>
    <symbol id="ic-receipt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3v18l2.5-1.5L10 21l2-1.5L14 21l2.5-1.5L19 21V3z"/><path d="M9 8h6M9 12h6M9 16h3"/></symbol>
    <symbol id="ic-recycle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 0 1-13.7 5.6M4 12a8 8 0 0 1 13.7-5.6"/><path d="M20 5v4h-4M4 19v-4h4"/></symbol>
    <symbol id="ic-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/></symbol>
    <symbol id="ic-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0M16.5 5.2a3.2 3.2 0 0 1 0 5.6M18 20a6 6 0 0 0-2.2-4.6"/></symbol>
    <symbol id="ic-book" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v16H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v4H6.5A2.5 2.5 0 0 1 4 19.5M9 7h7M9 11h5"/></symbol>
    <symbol id="ic-chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l3.5-4 3 2.5L20 7"/></symbol>
    <symbol id="ic-clipboard" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M9 12h6M9 16h4"/></symbol>
    <symbol id="ic-wrench" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 6.5a4.5 4.5 0 0 0 5.9 5.9l-8.6 8.6a2.5 2.5 0 0 1-3.5-3.5z"/><path d="M14.5 6.5 18 3l3 3-3.5 3.5"/></symbol>
    <symbol id="ic-cube" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5 21 7v10l-9 4.5L3 17V7z"/><path d="M3 7l9 4.5L21 7M12 11.5V21"/></symbol>
    <symbol id="ic-badge" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10.5" r="2.2"/><path d="M5.5 17a3.8 3.8 0 0 1 7 0M14.5 9.5h4M14.5 13h3"/></symbol>
    <symbol id="ic-megaphone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10v4a2 2 0 0 0 2 2h2l9 4V4L7 8H5a2 2 0 0 0-2 2z"/><path d="M19 9.5a3.5 3.5 0 0 1 0 5"/></symbol>
    <symbol id="ic-sliders" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V14M5 10V3M12 21v-9M12 8V3M19 21v-5M19 12V3"/><path d="M2.5 14h5M9.5 8h5M16.5 16h5"/></symbol>
    <symbol id="ic-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5 20 6v6c0 5-3.4 8.4-8 9.5C7.4 20.4 4 17 4 12V6z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="ic-language" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M2.6 12h18.8M12 2.5c2.5 2.6 3.8 6 3.8 9.5S14.5 18.9 12 21.5c-2.5-2.6-3.8-6-3.8-9.5S9.5 5.1 12 2.5z"/></symbol>
    <symbol id="ic-percent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M19 5 5 19"/><circle cx="7.5" cy="7.5" r="2.8"/><circle cx="16.5" cy="16.5" r="2.8"/></symbol>
    <symbol id="ic-cheque" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M6 10h5M6 14h3"/><path d="m14 13 2 2 4-4.5"/></symbol>
    <symbol id="ic-key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="4.5"/><path d="m11.2 11.2 8.3 8.3M16.5 16.5 18 15M18.5 18.5 20 17"/></symbol>
    <symbol id="ic-wallet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18v3"/><rect x="3" y="7.5" width="18" height="12" rx="2.5"/><circle cx="16.5" cy="13.5" r="1.2"/></symbol>
    <symbol id="ic-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4M8.5 14.5h2M8.5 17.5h7"/></symbol>
    <symbol id="ic-card" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h3"/></symbol>
    <symbol id="ic-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14.5v2.5"/></symbol>
    <symbol id="ic-layers" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2.5 9 5-9 5-9-5z"/><path d="m3 12.5 9 5 9-5M3 17l9 5 9-5"/></symbol>
    <symbol id="ic-history" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"/><path d="M3 3v4.5h4.5M12 7.5V12l3 2"/></symbol>
    <symbol id="ic-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="m8 12 2.7 2.7L16 9.5"/></symbol>
    <symbol id="ic-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h15M13 6l6 6-6 6"/></symbol>
    <symbol id="ic-dot" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7"/></symbol>
    <symbol id="ic-phone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 5.5h3M10.75 18.5h2.5"/></symbol>
    <symbol id="ic-doc" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2.5H7a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7.5z"/><path d="M14 2.5v5h5"/></symbol>
</svg>

<header class="nav" id="siteNav">
    <div class="wrap nav-inner">
        <a class="nav-logo" href="{{ url('/') }}" aria-label="Atriom">
            <img src="{{ asset('images/atriom-logo.svg') }}" alt="Atriom" width="128" height="32">
        </a>

        <nav class="nav-links" aria-label="{{ __('landing.nav.menu') }}">
            <a href="#platform">{{ __('landing.nav.platform') }}</a>
            <a href="#capabilities">{{ __('landing.nav.capabilities') }}</a>
            <a href="#egypt">{{ __('landing.nav.egypt') }}</a>
            <a href="#engineering">{{ __('landing.nav.engineering') }}</a>
        </nav>

        <div class="nav-actions">
            <a class="lang" href="{{ url('/locale/'.$otherLocale) }}" lang="{{ $otherLocale }}" aria-label="{{ $otherLanguage }}">
                <svg width="15" height="15" aria-hidden="true"><use href="#ic-language"/></svg>
                {{ $otherLanguage }}
            </a>
            <a class="btn btn-primary" href="{{ url('/admin') }}">{{ __('landing.nav.sign_in') }}</a>
        </div>
    </div>
</header>

<main id="main">

    {{-- ── Hero ──────────────────────────────────────────────────────────────────────────── --}}
    <div class="hero">
        <div class="hero-grid" aria-hidden="true"></div>
        <div class="wrap hero-layout">
          <div>
            <span class="eyebrow">{{ __('landing.hero.eyebrow') }}</span>
            <h1>
                {{ __('landing.hero.title') }}
                <span class="accent">{{ __('landing.hero.title_accent') }}</span>
            </h1>
            <p class="lede">{{ __('landing.hero.lede') }}</p>

            <div class="hero-cta">
                <a class="btn btn-primary btn-lg" href="{{ url('/admin') }}">
                    {{ __('landing.hero.cta_primary') }}
                    <svg class="arrow" width="16" height="16" aria-hidden="true"><use href="#ic-arrow"/></svg>
                </a>
                <a class="btn btn-ghost btn-lg" href="#platform">{{ __('landing.hero.cta_secondary') }}</a>
            </div>

            <p class="hero-trust"><span class="pulse" aria-hidden="true"></span>{{ __('landing.hero.trust') }}</p>
          </div>

          <div class="figure">
            <div class="doc">
                <div class="doc-head">
                    <span class="kind">{{ __('landing.hero.visual.invoice') }}</span>
                    <span class="ref">INV-AW-0004182</span>
                </div>
                <div class="doc-body">
                    <div class="row">
                        <span class="name">{{ __('landing.hero.visual.base_rent') }}<span class="tag">{{ __('landing.hero.visual.exempt') }}</span></span>
                        <span class="amt">120,000.00</span>
                    </div>
                    <div class="row">
                        <span class="name">{{ __('landing.hero.visual.service_charge') }}</span>
                        <span class="amt">18,000.00</span>
                    </div>
                    <div class="row">
                        <span class="name">{{ __('landing.hero.visual.vat') }} &middot; <bdi>14%</bdi></span>
                        <span class="amt">2,520.00</span>
                    </div>
                    <div class="row total">
                        <span class="name">{{ __('landing.hero.visual.total') }}</span>
                        <span class="amt">EGP 140,520.00</span>
                    </div>
                </div>
            </div>

            <p class="posts">
                <span>
                    <svg aria-hidden="true"><use href="#ic-arrow"/></svg>
                    {{ __('landing.hero.visual.posts_to') }}
                </span>
            </p>

            <div class="doc">
                <div class="doc-head">
                    <span class="kind">{{ __('landing.hero.visual.entry') }}</span>
                    <span class="ref">JE-0007431</span>
                </div>
                <div class="doc-body">
                    <div class="row">
                        <span class="name"><span class="side">{{ __('landing.hero.visual.debit') }}</span>{{ __('landing.hero.visual.receivable') }}</span>
                        <span class="amt">140,520.00</span>
                    </div>
                    <div class="row">
                        <span class="name"><span class="side">{{ __('landing.hero.visual.credit') }}</span>{{ __('landing.hero.visual.rent_revenue') }}</span>
                        <span class="amt">120,000.00</span>
                    </div>
                    <div class="row">
                        <span class="name"><span class="side">{{ __('landing.hero.visual.credit') }}</span>{{ __('landing.hero.visual.service_revenue') }}</span>
                        <span class="amt">18,000.00</span>
                    </div>
                    <div class="row">
                        <span class="name"><span class="side">{{ __('landing.hero.visual.credit') }}</span>{{ __('landing.hero.visual.vat_payable') }}</span>
                        <span class="amt">2,520.00</span>
                    </div>
                </div>
                <p class="balanced">
                    <svg aria-hidden="true"><use href="#ic-check"/></svg>
                    {{ __('landing.hero.visual.balanced') }}
                </p>
            </div>
          </div>
        </div>
    </div>

    {{-- ── Stats ─────────────────────────────────────────────────────────────────────────── --}}
    <section id="platform" style="padding-block-start:0">
        <div class="wrap">
            <h2 class="sr-only">{{ __('landing.stats.title') }}</h2>
            <div class="stats reveal">
                @foreach ($stats as $stat)
                    <div class="stat">
                        <div class="stat-value">{{ $stat['value'] }}</div>
                        <div class="stat-label">{{ __('landing.stats.'.$stat['key']) }}</div>
                    </div>
                @endforeach
            </div>
            <p class="stats-note">{{ __('landing.stats.note') }}</p>
        </div>
    </section>

    {{-- ── The money spine ───────────────────────────────────────────────────────────────── --}}
    <section class="band">
        <div class="wrap">
            <div class="section-head reveal">
                <span class="eyebrow">{{ __('landing.nav.platform') }}</span>
                <h2>{{ __('landing.spine.title') }}</h2>
                <p>{{ __('landing.spine.lede') }}</p>
            </div>

            <div class="spine">
                @foreach ($spine as $i => $key)
                    <article class="step reveal" style="transition-delay:{{ $i * 60 }}ms">
                        <span class="step-n">{{ $i + 1 }}</span>
                        <h3>{{ __('landing.spine.steps.'.$key.'.title') }}</h3>
                        <p>{{ __('landing.spine.steps.'.$key.'.body') }}</p>
                    </article>
                @endforeach
            </div>

            <p class="spine-note reveal">{{ __('landing.spine.note') }}</p>
        </div>
    </section>

    {{-- ── Capabilities ──────────────────────────────────────────────────────────────────── --}}
    <section id="capabilities">
        <div class="wrap">
            <div class="section-head reveal">
                <span class="eyebrow">{{ __('landing.nav.capabilities') }}</span>
                <h2>{{ __('landing.capabilities.title') }}</h2>
                <p>{{ __('landing.capabilities.lede') }}</p>
            </div>

            <div class="grid grid-3">
                @foreach ($capabilities as $key => $icon)
                    <article class="card reveal" style="transition-delay:{{ ($loop->index % 3) * 60 }}ms">
                        <span class="ico"><svg aria-hidden="true"><use href="#ic-{{ $icon }}"/></svg></span>
                        <h3>{{ __('landing.capabilities.items.'.$key.'.title') }}</h3>
                        <p>{{ __('landing.capabilities.items.'.$key.'.body') }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Surfaces ──────────────────────────────────────────────────────────────────────── --}}
    <section class="band">
        <div class="wrap">
            <div class="section-head reveal">
                <span class="eyebrow">{{ __('landing.footer.surfaces') }}</span>
                <h2>{{ __('landing.surfaces.title') }}</h2>
                <p>{{ __('landing.surfaces.lede') }}</p>
            </div>

            <div class="grid grid-4">
                @foreach (['admin' => url('/admin'), 'portal' => url('/portal'), 'vendor' => url('/vendor')] as $key => $href)
                    <a class="surface-card reveal" href="{{ $href }}" style="transition-delay:{{ $loop->index * 60 }}ms">
                        <span class="label">{{ __('landing.surfaces.'.$key.'.label') }}</span>
                        <h3>{{ __('landing.surfaces.'.$key.'.title') }}</h3>
                        <p>{{ __('landing.surfaces.'.$key.'.body') }}</p>
                        <span class="go">
                            {{ __('landing.surfaces.'.$key.'.action') }}
                            <svg aria-hidden="true"><use href="#ic-arrow"/></svg>
                        </span>
                    </a>
                @endforeach

                {{-- The app is installed, not opened from here, so it is a card and not a link. --}}
                <div class="surface-card static reveal" style="transition-delay:180ms">
                    <span class="label">{{ __('landing.surfaces.mobile.label') }}</span>
                    <h3>{{ __('landing.surfaces.mobile.title') }}</h3>
                    <p>{{ __('landing.surfaces.mobile.body') }}</p>
                    <span class="go">
                        <svg aria-hidden="true"><use href="#ic-phone"/></svg>
                        {{ __('landing.surfaces.mobile.action') }}
                    </span>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Built for Egypt ───────────────────────────────────────────────────────────────── --}}
    <section id="egypt">
        <div class="wrap">
            <div class="section-head reveal">
                <span class="eyebrow">{{ __('landing.nav.egypt') }}</span>
                <h2>{{ __('landing.egypt.title') }}</h2>
                <p>{{ __('landing.egypt.lede') }}</p>
            </div>

            <div class="grid grid-4">
                @foreach ($egypt as $key => $icon)
                    <article class="card amber reveal" style="transition-delay:{{ ($loop->index % 4) * 50 }}ms">
                        <span class="ico"><svg aria-hidden="true"><use href="#ic-{{ $icon }}"/></svg></span>
                        <h3>{{ __('landing.egypt.items.'.$key.'.title') }}</h3>
                        <p>{{ __('landing.egypt.items.'.$key.'.body') }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Engineering ───────────────────────────────────────────────────────────────────── --}}
    <section id="engineering" class="band">
        <div class="wrap">
            <div class="section-head reveal">
                <span class="eyebrow">{{ __('landing.nav.engineering') }}</span>
                <h2>{{ __('landing.engineering.title') }}</h2>
                <p>{{ __('landing.engineering.lede') }}</p>
            </div>

            <div class="grid grid-3">
                @foreach ($engineering as $key => $icon)
                    <article class="card reveal" style="transition-delay:{{ ($loop->index % 3) * 60 }}ms">
                        <span class="ico"><svg aria-hidden="true"><use href="#ic-{{ $icon }}"/></svg></span>
                        <h3>{{ __('landing.engineering.items.'.$key.'.title') }}</h3>
                        <p>{{ __('landing.engineering.items.'.$key.'.body') }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Automation ────────────────────────────────────────────────────────────────────── --}}
    <section>
        <div class="wrap">
            <div class="section-head reveal">
                <h2>{{ __('landing.automation.title') }}</h2>
                <p>{{ __('landing.automation.lede') }}</p>
            </div>

            <div class="ticks">
                @foreach ($automation as $key)
                    <div class="tick reveal" style="transition-delay:{{ ($loop->index % 2) * 60 }}ms">
                        <svg aria-hidden="true"><use href="#ic-dot"/></svg>
                        <span>{{ __('landing.automation.items.'.$key) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Documents ─────────────────────────────────────────────────────────────────────── --}}
    <section class="band">
        <div class="wrap">
            <div class="section-head reveal">
                <h2>{{ __('landing.documents.title') }}</h2>
                <p>{{ __('landing.documents.lede') }}</p>
            </div>

            <div class="chips reveal">
                @foreach ($documents as $key)
                    <span class="chip">
                        <svg aria-hidden="true"><use href="#ic-doc"/></svg>
                        {{ __('landing.documents.items.'.$key) }}
                    </span>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── CTA ───────────────────────────────────────────────────────────────────────────── --}}
    <section>
        <div class="wrap">
            <div class="cta-box reveal">
                <h2>{{ __('landing.cta.title') }}</h2>
                <p>{{ __('landing.cta.body') }}</p>
                <div class="cta-actions">
                    <a class="btn btn-primary btn-lg" href="{{ url('/admin') }}">
                        {{ __('landing.cta.admin') }}
                        <svg class="arrow" width="16" height="16" aria-hidden="true"><use href="#ic-arrow"/></svg>
                    </a>
                    <a class="btn btn-ghost btn-lg" href="{{ url('/portal') }}">{{ __('landing.cta.portal') }}</a>
                    <a class="btn btn-ghost btn-lg" href="{{ url('/vendor') }}">{{ __('landing.cta.vendor') }}</a>
                </div>
            </div>
        </div>
    </section>
</main>

<footer>
    <div class="wrap">
        <div class="foot-grid">
            <div class="foot-brand">
                <img src="{{ asset('images/atriom-logo.svg') }}" alt="Atriom" width="120" height="30">
                <p>{{ __('landing.footer.tagline') }}</p>
            </div>

            <div class="foot-col">
                <h4>{{ __('landing.footer.product') }}</h4>
                <ul>
                    <li><a href="#platform">{{ __('landing.nav.platform') }}</a></li>
                    <li><a href="#capabilities">{{ __('landing.nav.capabilities') }}</a></li>
                    <li><a href="#egypt">{{ __('landing.nav.egypt') }}</a></li>
                    <li><a href="#engineering">{{ __('landing.nav.engineering') }}</a></li>
                </ul>
            </div>

            <div class="foot-col">
                <h4>{{ __('landing.footer.surfaces') }}</h4>
                <ul>
                    <li><a href="{{ url('/admin') }}">{{ __('landing.surfaces.admin.title') }}</a></li>
                    <li><a href="{{ url('/portal') }}">{{ __('landing.surfaces.portal.title') }}</a></li>
                    <li><a href="{{ url('/vendor') }}">{{ __('landing.surfaces.vendor.title') }}</a></li>
                    <li><a href="{{ url('/health') }}">{{ __('landing.footer.health') }}</a></li>
                </ul>
            </div>

            <div class="foot-col">
                <h4>{{ __('landing.footer.sign_in') }}</h4>
                <ul>
                    <li><a href="{{ url('/admin/login') }}">{{ __('landing.surfaces.admin.title') }}</a></li>
                    <li><a href="{{ url('/portal/login') }}">{{ __('landing.surfaces.portal.title') }}</a></li>
                    <li><a href="{{ url('/vendor/login') }}">{{ __('landing.surfaces.vendor.title') }}</a></li>
                    <li><a href="{{ url('/locale/'.$otherLocale) }}" lang="{{ $otherLocale }}">{{ $otherLanguage }}</a></li>
                </ul>
            </div>
        </div>

        <div class="foot-bottom">
            <span>&copy; {{ date('Y') }} Atriom &middot; {{ __('landing.footer.rights') }}</span>
            <span class="powered">{{ __('landing.footer.powered_by') }} <strong>TRITECH</strong></span>
        </div>
    </div>
</footer>

<script>
    document.body.classList.remove('no-js');

    // Border under the nav only once the page has moved — a hairline over the hero reads as a seam.
    var nav = document.getElementById('siteNav');
    var onScroll = function () { nav.dataset.scrolled = window.scrollY > 8 ? 'true' : 'false'; };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    // Reveal on entry. Guarded on the API AND on the reduced-motion preference: without the guard
    // a visitor who asked for no motion still gets everything below the fold hidden until it is
    // observed, which for them is a blank page rather than a calm one.
    var wants = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var items = document.querySelectorAll('.reveal');

    if (wants || !('IntersectionObserver' in window)) {
        items.forEach(function (el) { el.classList.add('in'); });
    } else {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

        items.forEach(function (el) { io.observe(el); });
    }
</script>
</body>
</html>
