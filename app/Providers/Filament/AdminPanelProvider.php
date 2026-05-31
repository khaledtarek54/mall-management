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
     * colour variable from the active tenant's `primary_color` hex. Filament 4's
     * `->colors()` is evaluated once at panel boot so we can't dynamic-set
     * it there; the CSS-var override is the supported way to per-tenant-skin
     * the panel chrome. Empty string when no tenant / no colour set.
     */
    protected static function renderPerTenantThemeOverride(): string
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Asset || $tenant->isAllProperties() || ! $tenant->primary_color) {
            return '';
        }

        $hex = ltrim($tenant->primary_color, '#');
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '';
        }

        // Convert hex → comma-separated RGB triplet so we can hand it to
        // every Filament `--primary-XXX` variant. Filament's tailwind config
        // expects RGB without rgb() wrapping.
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $rgb = "{$r}, {$g}, {$b}";

        return <<<HTML
<style>
:root {
    --primary-50: {$rgb};
    --primary-100: {$rgb};
    --primary-200: {$rgb};
    --primary-300: {$rgb};
    --primary-400: {$rgb};
    --primary-500: {$rgb};
    --primary-600: {$rgb};
    --primary-700: {$rgb};
    --primary-800: {$rgb};
    --primary-900: {$rgb};
    --primary-950: {$rgb};
}
</style>
HTML;
    }
}
