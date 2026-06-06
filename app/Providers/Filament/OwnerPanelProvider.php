<?php

namespace App\Providers\Filament;

use App\Filament\Owner\Widgets\PortfolioStats;
use App\Http\Middleware\SetLocale;
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

class OwnerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('owner')
            ->path('owner')
            ->login()
            // Owners see portfolio-level events on the bell — currently
            // limited to SLA breaches across their owned assets. Mail is
            // not used on the owner side in v1.
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->brandName('Atriom · Owner Portal')
            // Explicit light/dark variants wired to Filament's theme toggle —
            // the auto atriom-logo.svg keys off the OS scheme and desyncs.
            ->brandLogo(asset('images/atriom-logo-light.svg'))
            ->darkModeBrandLogo(asset('images/atriom-logo-dark.svg'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('atriom-favicon.svg'))
            ->discoverResources(in: app_path('Filament/Owner/Resources'), for: 'App\\Filament\\Owner\\Resources')
            ->discoverPages(in: app_path('Filament/Owner/Pages'), for: 'App\\Filament\\Owner\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Owner/Widgets'), for: 'App\\Filament\\Owner\\Widgets')
            ->widgets([
                PortfolioStats::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Portfolio')->label(fn () => __('admin.groups.portfolio')),
                NavigationGroup::make('Operations')->label(fn () => __('admin.groups.operations')),
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
