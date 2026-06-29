@php
    $current = app()->getLocale();
    $isAr = fn (string $lang) => $lang === $current;
@endphp
<style>
    .atriom-lang-switch {
        display: inline-flex; align-items: center; gap: 2px;
        padding: 3px; margin-inline-start: 0.75rem;
        border-radius: 8px;
        border: 1px solid rgba(0,0,0,0.12);
        background: rgba(0,0,0,0.03);
    }
    .dark .atriom-lang-switch {
        border-color: rgba(255,255,255,0.14);
        background: rgba(255,255,255,0.04);
    }
    .atriom-lang-pill {
        display: inline-block; padding: 4px 10px; border-radius: 6px;
        font-size: 11px; font-weight: 600; text-decoration: none;
        line-height: 1; letter-spacing: 0.5px;
        background: transparent; color: #71717A;
        transition: all 0.15s ease;
    }
    .atriom-lang-pill:hover { color: #18181B; }
    .dark .atriom-lang-pill { color: #A1A1AA; }
    .dark .atriom-lang-pill:hover { color: #FAFAFA; }
    .atriom-lang-pill--active {
        background: #18181B;
        color: #FAFAFA;
        box-shadow: 0 1px 2px rgba(0,0,0,0.12);
    }
    .atriom-lang-pill--active:hover { color: #FAFAFA; }
    .dark .atriom-lang-pill--active {
        background: #FAFAFA;
        color: #18181B;
    }
    .dark .atriom-lang-pill--active:hover { color: #18181B; }
</style>
<div class="atriom-lang-switch" role="group" aria-label="Language">
    {{-- aria-current marks the active language (valid on links; aria-pressed is
         button-only and tripped the axe aria-allowed-attr WCAG check). --}}
    <a href="{{ route('locale.switch', 'en') }}"
       class="atriom-lang-pill {{ $isAr('en') ? 'atriom-lang-pill--active' : '' }}"
       @if ($isAr('en')) aria-current="true" @endif
       aria-label="English">EN</a>
    <a href="{{ route('locale.switch', 'ar') }}"
       class="atriom-lang-pill {{ $isAr('ar') ? 'atriom-lang-pill--active' : '' }}"
       @if ($isAr('ar')) aria-current="true" @endif
       aria-label="العربية">عربي</a>
</div>
