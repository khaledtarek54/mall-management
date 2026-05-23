<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Widgets\ActionRequired;
use App\Filament\Admin\Widgets\ArAging;
use App\Filament\Admin\Widgets\ExpiringLeases;
use App\Filament\Admin\Widgets\MallStats;
use App\Filament\Admin\Widgets\MonthlyRevenueTrend;
use App\Filament\Admin\Widgets\OpenMaintenanceRequests;
use App\Filament\Admin\Widgets\RecentPayments;
use App\Filament\Admin\Widgets\TenantMix;
use App\Filament\Admin\Widgets\TopTenants;
use App\Support\CurrentOperator;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->brandName(fn (): string => CurrentOperator::get()?->name ?? 'Mall Management')
            ->brandLogo(function (): ?string {
                $operator = CurrentOperator::get();
                if ($operator) {
                    return $operator->logoUrl();
                }
                return asset('images/jawad-logo.png');
            })
            ->brandLogoHeight('2.5rem')
            ->favicon(function (): ?string {
                $operator = CurrentOperator::get();
                if ($operator) {
                    return $operator->faviconUrl();
                }
                return asset('jawad-favicon.png');
            })
            ->colors([
                'primary' => Color::hex('#C9A961'),
                'gray' => Color::Stone,
                'danger' => Color::Red,
                'info' => Color::Blue,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->font('Inter')
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            ->widgets([
                ActionRequired::class,
                MallStats::class,
                ArAging::class,
                TenantMix::class,
                MonthlyRevenueTrend::class,
                ExpiringLeases::class,
                OpenMaintenanceRequests::class,
                TopTenants::class,
                RecentPayments::class,
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
