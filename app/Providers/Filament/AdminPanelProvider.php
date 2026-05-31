<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Widgets\ActionRequired;
use App\Filament\Admin\Widgets\ArAging;
use App\Filament\Admin\Widgets\EnergyConsumptionTrend;
use App\Filament\Admin\Widgets\EtaCompliance;
use App\Filament\Admin\Widgets\ExpiringLeases;
use App\Filament\Admin\Widgets\LeasingPipeline;
use App\Filament\Admin\Widgets\MallStats;
use App\Filament\Admin\Widgets\MonthlyRevenueTrend;
use App\Filament\Admin\Widgets\OpenMaintenanceRequests;
use App\Filament\Admin\Widgets\RecentPayments;
use App\Filament\Admin\Widgets\SetupGuide;
use App\Filament\Admin\Widgets\TenantMix;
use App\Filament\Admin\Widgets\TopTenants;
use App\Models\Asset;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Admin password lifecycle. Operators can recover access without
            // a super_admin reset, and can change their own password from the
            // top-bar avatar (audit M17 F-64 / D-49).
            ->passwordReset()
            ->profile(isSimple: false)
            // TOTP 2FA via Google Authenticator etc. Enforced only on the
            // super_admin role (full-system access); other roles can opt
            // in via the top-bar menu item. Audit M17 F-65 / D-50.
            ->plugin(
                \Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticationPlugin::make()
                    ->enableTwoFactorAuthentication()
                    ->addTwoFactorMenuItem()
                    ->forceTwoFactorSetup(fn (): bool => auth()->user()?->hasRole('super_admin') === true)
            )
            // Branding resolves from the active property tenant when one is
            // set. Each Asset can carry its own logo (MediaLibrary `logo`
            // collection) + favicon + primary-colour hex. The synthetic
            // ALL pseudo-tenant + the no-tenant case both fall back to
            // platform Atriom branding.
            ->brandName(fn (): string => self::resolveBrandName())
            ->brandLogo(fn (): string => self::resolveBrandLogo())
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): string => self::resolveFavicon())
            ->tenant(Asset::class, slugAttribute: 'code')
            ->tenantRegistration(\App\Filament\Admin\Pages\Tenancy\RegisterProperty::class)
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            ->widgets([
                SetupGuide::class,
                ActionRequired::class,
                MallStats::class,
                EtaCompliance::class,
                LeasingPipeline::class,
                // AR Aging + Tenant Mix are half-width (columnSpan = 1) so
                // they render side by side as a single row of charts.
                ArAging::class,
                TenantMix::class,
                MonthlyRevenueTrend::class,
                ExpiringLeases::class,
                OpenMaintenanceRequests::class,
                TopTenants::class,
                RecentPayments::class,
                EnergyConsumptionTrend::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Operations')->label(fn () => __('admin.groups.operations')),
                NavigationGroup::make('Billing')->label(fn () => __('admin.groups.billing')),
                NavigationGroup::make('Reports')->label(fn () => __('admin.groups.reports')),
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
                \App\Http\Middleware\SetLocale::class,
            ])
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
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

    protected static function resolveBrandLogo(): string
    {
        $tenant = Filament::getTenant();
        if ($tenant instanceof Asset && ! $tenant->isAllProperties()) {
            if ($logo = $tenant->logoUrl()) {
                return $logo;
            }
        }
        return asset('images/atriom-logo.svg');
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

        $hex = '#' . ltrim($tenant->primary_color, '#');
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
