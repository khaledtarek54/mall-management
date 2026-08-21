<?php

namespace App\Providers\Filament;

use App\Filament\Portal\Widgets\AccountBalance;
use App\Filament\Portal\Widgets\OpenTenantRequests;
use App\Http\Middleware\SetLocale;
use App\Support\Filament\PortalBranding;
use App\Support\Search\AtriomGlobalSearchProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('portal')
            ->path('portal')
            // One shared theme file sets panel density for BOTH panels — see
            // resources/css/filament/theme.css for the single knob that controls it.
            ->viteTheme('resources/css/filament/theme.css')
            ->login()
            // Tenant password lifecycle without an operator round-trip
            // (audit M02 F-8 / M11 F-44 / D-49). Requires MAIL_* env set in
            // production. EditProfile lets the tenant change their own
            // password from the top-bar avatar.
            ->passwordReset()
            // ⚠️ Without this the panel resolves Password::broker(null), which config/auth.php
            // defaults to `users` — App\Models\User. So the portal's reset ran against the ADMIN
            // table: a TenantUser could NEVER reset (their email simply isn't there, and the page
            // said "we can't find a user with that email address"), while an operator's email
            // typed into the public portal form mailed that admin a genuine reset link built for
            // THIS panel. The `tenant_users` broker existed the whole time and nothing used it.
            ->authPasswordBroker('tenant_users')
            ->profile(isSimple: false)
            // Top-bar bell — tenants see invoice issued / payment received /
            // maintenance status change / sales declaration locked here.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // WHITE-LABELLED, like the admin panel has been since it gained tenancy (EG-22). A
            // retailer signing into their landlord's portal saw the software vendor's name in an
            // untranslated English literal; they now see their mall's name, logo, favicon and
            // colour. `PortalBranding` answers null for a tenant trading in SEVERAL malls — see its
            // docblock for why that is the honest answer rather than a missing feature.
            //
            // Closures, not values: a panel builder argument is evaluated ONCE at boot, so a value
            // here could not depend on who is signed in. Same trap that made `->colors()` and the
            // 2FA condition unusable per-user.
            ->brandName(fn (): string => PortalBranding::brandName())
            ->brandLogo(fn (): string => PortalBranding::logo())
            ->darkModeBrandLogo(fn (): string => PortalBranding::logo(dark: true))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): string => PortalBranding::favicon())
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => PortalBranding::themeOverride(),
            )
            ->authGuard('portal')
            // The portal had a live global search bar that nobody had configured:
            // whichever of its resources happened to carry a $recordTitleAttribute
            // was searchable, the other three were not, and a tenant typing one
            // character triggered a scan of every one of them. Same provider as
            // the admin panel, so the length floor, the ordering and the
            // exact-match promotion behave identically on both sides. Tenant
            // scoping is unaffected — it lives in each resource's
            // getEloquentQuery(), which global search runs through.
            ->globalSearch(AtriomGlobalSearchProvider::class)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->globalSearchDebounce('400ms')
            ->discoverResources(in: app_path('Filament/Portal/Resources'), for: 'App\\Filament\\Portal\\Resources')
            ->discoverPages(in: app_path('Filament/Portal/Pages'), for: 'App\\Filament\\Portal\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Portal/Widgets'), for: 'App\\Filament\\Portal\\Widgets')
            ->widgets([
                AccountBalance::class,
                OpenTenantRequests::class,
            ])
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
