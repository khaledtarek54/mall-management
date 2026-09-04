<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * **The contractor's panel at `/vendor`** — step 2 of `docs/modules/12b-VENDOR-PORTAL-DESIGN.md`.
 *
 * Built before any feature hangs off it, deliberately: the security model is the thing worth
 * proving first, and every verb the portal grows is a screen over something that already exists.
 *
 * **Smaller than the tenant portal, on purpose.** No global search (there is nothing to search — the
 * portal is a list of *your* jobs, and a search box over one narrow list reads as an invitation to
 * look for other people's), no dashboard widgets, no branding by property (a contractor works across
 * malls, so `PortalBranding`'s "one mall or the platform" rule would resolve to the platform for
 * almost every contractor anyway). What it does share is the shape: a company, several people, its
 * own guard, every query scoped to the company.
 *
 * **`authPasswordBroker` is named explicitly and that line is load-bearing.** Without it Filament
 * resolves `Password::broker(null)`, which `config/auth.php` defaults to `users` — so the reset
 * would run against the ADMIN table: a contractor could never reset, while an operator's email typed
 * into the public vendor form would mail that admin a genuine reset link built for THIS panel. That
 * is not hypothetical; it is exactly what the tenant portal shipped with, and its comment in
 * `PortalPanelProvider` records it.
 */
class VendorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('vendor')
            ->viteTheme('resources/css/filament/theme.css')
            ->login()
            ->passwordReset()
            ->authPasswordBroker('vendor_contacts')
            ->authGuard('vendor')
            ->profile(isSimple: false)
            // The bell is how a dispatch reaches them (step 6). Registered now so the column exists
            // in the panel from the start rather than being retrofitted around a live inbox.
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            // NO GLOBAL SEARCH — the decision in this class's docblock, finally implemented.
            //
            // Filament's panel default is ON, and a resource is globally searchable as soon as it
            // has a record-title attribute, so the box shipped anyway. Measured 2026-09-04 with the
            // vendor panel current: `Filament::isGlobalSearchEnabled()` answered TRUE, the provider
            // was Filament's stock `DefaultGlobalSearchProvider` rather than
            // `AtriomGlobalSearchProvider`, and it searched `facility_work_orders.reference` RAW —
            // the only raw-column global search left in the application, and invisible to
            // `SearchPolicy`'s gate because that gate discovered resources from a hardcoded
            // Admin+Portal list. Never a leak (`VendorScope::jobs()` scopes the query), but an
            // unfolded query against a blob-free column, and a control the design says not to offer.
            ->globalSearch(false)
            ->discoverResources(in: app_path('Filament/Vendor/Resources'), for: 'App\\Filament\\Vendor\\Resources')
            ->discoverPages(in: app_path('Filament/Vendor/Pages'), for: 'App\\Filament\\Vendor\\Pages')
            ->discoverWidgets(in: app_path('Filament/Vendor/Widgets'), for: 'App\\Filament\\Vendor\\Widgets')
            ->sidebarCollapsibleOnDesktop()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
