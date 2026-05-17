@php
    $current = app()->getLocale();
    $isAr = fn (string $lang) => $lang === $current;
    $base = 'display:inline-block;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;line-height:1;letter-spacing:0.5px;transition:all 0.15s;';
    $active = 'background:#C9A961;color:#1A1A1A;box-shadow:0 1px 2px rgba(0,0,0,0.08);';
    $inactive = 'background:transparent;color:#8C8478;';
@endphp
<div style="display:inline-flex;align-items:center;gap:2px;padding:3px;margin-inline-start:0.75rem;border-radius:8px;border:1px solid rgba(201,169,97,0.35);background:rgba(26,26,26,0.45);"
     role="group" aria-label="Language">
    <a href="{{ route('locale.switch', 'en') }}"
       style="{{ $base }}{{ $isAr('en') ? $active : $inactive }}"
       aria-pressed="{{ $isAr('en') ? 'true' : 'false' }}"
       aria-label="English"
       onmouseover="if(!this.dataset.active){this.style.color='#C9A961'}"
       onmouseout="if(!this.dataset.active){this.style.color='#8C8478'}"
       @if($isAr('en')) data-active="1" @endif>EN</a>
    <a href="{{ route('locale.switch', 'ar') }}"
       style="{{ $base }}{{ $isAr('ar') ? $active : $inactive }}"
       aria-pressed="{{ $isAr('ar') ? 'true' : 'false' }}"
       aria-label="العربية"
       onmouseover="if(!this.dataset.active){this.style.color='#C9A961'}"
       onmouseout="if(!this.dataset.active){this.style.color='#8C8478'}"
       @if($isAr('ar')) data-active="1" @endif>عربي</a>
</div>
