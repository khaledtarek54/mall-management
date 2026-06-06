{{-- Platform attribution rendered in the footer of every panel. Filament's
     FOOTER hook fires on both the main and simple (login) layouts, so this one
     partial covers all panel pages.

     Styling is inline on purpose: render-hook markup isn't scanned by Filament's
     precompiled CSS, so arbitrary Tailwind utilities here would not exist in the
     stylesheet. `var(--primary-600)` is a complete colour in this app's Filament
     build (see AdminPanelProvider::renderPerTenantThemeOverride), so "TriTech"
     adopts each panel's brand accent — amber by default, the active property's
     colour on the admin panel — and the muted grey reads in light and dark. --}}
<div style="display:flex;align-items:center;justify-content:center;gap:0.4rem;padding:1.25rem 1rem;font-size:0.75rem;letter-spacing:0.04em;color:#9ca3af;">
    <span>Powered by</span>
    <span style="font-weight:600;color:var(--primary-600, #d97706);">TriTech</span>
</div>
