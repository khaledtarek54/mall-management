<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Tenancy\RegisterProperty;
use App\Http\Middleware\SetLocale;
use App\Models\Asset;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticationPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // One shared theme file sets panel density for BOTH panels — see
            // resources/css/filament/theme.css for the single knob that controls it.
            ->viteTheme('resources/css/filament/theme.css')
            ->login(Login::class)
            // Admin password lifecycle. Operators can recover access without
            // a super_admin reset, and can change their own password from the
            // top-bar avatar (audit M17 F-64 / D-49).
            ->passwordReset()
            // Simple (standalone) profile page — NOT the full-chrome variant.
            // This panel has property tenancy, but the profile route
            // (/admin/profile) is not tenant-scoped, so Filament::getTenant()
            // is null there. The full-chrome layout renders the property
            // switcher, whose label calls getTenantName($tenant) — with a null
            // tenant that throws a TypeError and 500s the page. The simple
            // layout has no switcher, so it renders cleanly while still letting
            // operators edit their name / email / password (audit M17 F-64 / D-49).
            ->profile()
            // Top-bar bell icon for portal-submitted maintenance, new sales
            // declarations awaiting review, SLA breaches, and operator-
            // initiated audit events. Database channel only on the admin
            // side; mail goes to the tenant-facing notifications.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // TOTP 2FA via Google Authenticator etc. Enforced only on the
            // super_admin role (full-system access); other roles can opt
            // in via the top-bar menu item. Audit M17 F-65 / D-50.
            ->plugin(
                TwoFactorAuthenticationPlugin::make()
                    ->enableTwoFactorAuthentication()
                    ->addTwoFactorMenuItem()
                    ->forceTwoFactorSetup(fn (): bool => auth()->user()?->hasAnyRole(config('security.force_2fa_roles', ['super_admin'])) === true)
            )
            // Branding resolves from the active property tenant when one is
            // set. Each Asset can carry its own logo (MediaLibrary `logo`
            // collection) + favicon + primary-colour hex. The synthetic
            // ALL pseudo-tenant + the no-tenant case both fall back to
            // platform Atriom branding.
            ->brandName(fn (): string => self::resolveBrandName())
            ->brandLogo(fn (): string => self::resolveBrandLogo())
            ->darkModeBrandLogo(fn (): string => self::resolveBrandLogo(dark: true))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): string => self::resolveFavicon())
            ->tenant(Asset::class, slugAttribute: 'code')
            ->tenantRegistration(RegisterProperty::class)
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            // Deliberately empty: the dashboard is composed per role by
            // App\Support\DashboardLayout (see App\Filament\Admin\Pages\Dashboard).
            // Listing widgets here would publish them to every role again — Filament's
            // dashboard renders the panel's widget list and leaves gating to each widget,
            // which is how the monthly-close receivables reached HR and marketing.
            ->widgets([])
            ->navigationGroups([
                // Sidebar organized by department (FR DEPT). Access to each
                // resource is still RBAC; this is just the grouping.
                NavigationGroup::make('Leasing')->label(fn () => __('admin.groups.leasing')),
                NavigationGroup::make('Operations')->label(fn () => __('admin.groups.operations')),
                NavigationGroup::make('Accounting')->label(fn () => __('admin.groups.accounting')),
                NavigationGroup::make('Marketing')->label(fn () => __('admin.groups.marketing')),
                NavigationGroup::make('HR')->label(fn () => __('admin.groups.hr')),
                NavigationGroup::make('Settings')->label(fn () => __('admin.groups.settings')),
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
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => self::renderPerTenantThemeOverride(),
            )
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Brand label for the topbar. Per-property name when a tenant is active
     * and isn't the synthetic All-Properties pseudo-asset.
     */
    protected static function resolveBrandName(): string
    {
        $tenant = Filament::getTenant();
        if ($tenant instanceof Asset && ! $tenant->isAllProperties()) {
            return $tenant->name;
        }

        return 'Atriom';
    }

    /**
     * Brand logo for the active property, else the platform wordmark. We serve
     * an explicit light/dark variant rather than the auto-adapting
     * atriom-logo.svg — that file keys off the OS `prefers-color-scheme`, which
     * desyncs from Filament's own in-app theme toggle (light panel + dark OS
     * rendered a cream wordmark on a white dashboard). Wiring the variant to
     * Filament's mode keeps the logo readable in both. A per-property logo is
     * used for both modes (each Asset carries a single logo asset).
     */
    protected static function resolveBrandLogo(bool $dark = false): string
    {
        $tenant = Filament::getTenant();
        if ($tenant instanceof Asset && ! $tenant->isAllProperties()) {
            if ($logo = $tenant->logoUrl()) {
                return $logo;
            }
        }

        return asset($dark ? 'images/atriom-logo-dark.svg' : 'images/atriom-logo-light.svg');
    }

    protected static function resolveFavicon(): string
    {
        $tenant = Filament::getTenant();
        if ($tenant instanceof Asset && ! $tenant->isAllProperties()) {
            if ($favicon = $tenant->faviconUrl()) {
                return $favicon;
            }
        }

        return asset('atriom-favicon.svg');
    }

    /**
     * Inject a per-request <style> block overriding Filament's CSS primary
     * colour variables from the active tenant's `primary_color` hex.
     *
     * Filament 4's `->colors()` is evaluated once at panel boot, so to
     * per-tenant-skin the chrome we override the `--primary-N` palette per
     * request. Filament's compiled CSS uses these directly
     * (e.g. `background-color: var(--primary-600)`), so the values must be
     * complete colours — not RGB triplets. We pin the hex at the 500 shade
     * and derive lighter (50-400) and darker (600-950) variations with
     * `color-mix()`, which is supported in all evergreen browsers.
     *
     * Empty string when no tenant / not a real tenant / no colour set.
     */
    protected static function renderPerTenantThemeOverride(): string
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Asset || $tenant->isAllProperties() || ! $tenant->primary_color) {
            return '';
        }

        $hex = '#'.ltrim($tenant->primary_color, '#');
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return '';
        }

        return <<<HTML
<style>
:root {
    --primary-50:  color-mix(in oklab, {$hex} 6%,  white);
    --primary-100: color-mix(in oklab, {$hex} 12%, white);
    --primary-200: color-mix(in oklab, {$hex} 24%, white);
    --primary-300: color-mix(in oklab, {$hex} 40%, white);
    --primary-400: color-mix(in oklab, {$hex} 65%, white);
    --primary-500: {$hex};
    --primary-600: color-mix(in oklab, {$hex} 88%, black);
    --primary-700: color-mix(in oklab, {$hex} 70%, black);
    --primary-800: color-mix(in oklab, {$hex} 55%, black);
    --primary-900: color-mix(in oklab, {$hex} 40%, black);
    --primary-950: color-mix(in oklab, {$hex} 25%, black);
}
</style>
HTML;
    }
}
