<?php

namespace App\Providers\Filament;

use App\Filament\Owner\Widgets\PortfolioStats;
use App\Models\Operator;
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
use Illuminate\Support\Facades\Auth;

class OwnerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('owner')
            ->path('owner')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->brandName(fn (): string => self::resolveOperator()?->name ?? 'Atriom · Owner Portal')
            ->brandLogo(function (): ?string {
                $operator = self::resolveOperator();
                return $operator?->logoUrl() ?? asset('images/atriom-logo.svg');
            })
            ->brandLogoHeight('2.5rem')
            ->favicon(function (): ?string {
                $operator = self::resolveOperator();
                return $operator?->faviconUrl() ?? asset('atriom-favicon.svg');
            })
            ->colors([
                'primary' => Color::Zinc,
                'gray' => Color::Zinc,
                'danger' => Color::Red,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->darkMode(true)
            ->font('Inter')
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
                \App\Http\Middleware\SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Resolve the operator brand to display for the current owner: if all their
     * owned assets share one operator, use that operator's brand. Otherwise fall
     * back to the default (no specific brand).
     */
    protected static function resolveOperator(): ?Operator
    {
        $user = Auth::user();
        if (! $user || ! method_exists($user, 'ownedAssets')) {
            return null;
        }

        $operatorIds = $user->ownedAssets()
            ->withoutGlobalScopes()
            ->pluck('operator_id')
            ->unique()
            ->filter()
            ->values();

        if ($operatorIds->count() !== 1) {
            return null;
        }

        return Operator::find($operatorIds->first());
    }
}
