<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Tenancy\RegisterProperty;
use App\Http\Middleware\ForceTwoFactorForRoles;
use App\Http\Middleware\SetLocale;
use App\Models\Asset;
use App\Support\Filament\PanelBranding;
use App\Support\Navigation;
use App\Support\Search\AtriomGlobalSearchProvider;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
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
            // TOTP 2FA via Google Authenticator etc. Which roles are forced is
            // decided per request in ForceTwoFactorForRoles; everyone else can opt
            // in via the top-bar menu item. Audit M17 F-65 / D-50.
            //
            // `condition: true` is load-bearing and must NOT become a closure again.
            // The plugin evaluates this argument when the PANEL IS REGISTERED — at
            // boot, before any request is authenticated — and stores a plain bool. The
            // previous `fn () => auth()->user()?->hasAnyRole(...)` therefore evaluated
            // against a null user, stored false, and the panel silently dropped the
            // enforcement middleware: 2FA was enforced on NOBODY, super_admin included,
            // while the config and the env var described a mechanism that was "built".
            // Same trap as ->colors() below — a panel builder argument cannot depend on
            // the current user. Pinned by TwoFactorEnforcementTest.
            ->plugin(
                TwoFactorAuthenticationPlugin::make()
                    ->enableTwoFactorAuthentication()
                    ->addTwoFactorMenuItem()
                    ->forceTwoFactorSetup(
                        condition: true,
                        middleware: ForceTwoFactorForRoles::class,
                    )
            )
            // Branding resolves from the active property tenant when one is
            // set. Each Asset can carry its own logo (MediaLibrary `logo`
            // collection) + favicon + primary-colour hex. The synthetic
            // ALL pseudo-tenant + the no-tenant case both fall back to
            // platform Atriom branding.
            ->brandName(fn (): string => self::resolveBrandName())
            ->brandLogo(fn (): ?string => self::resolveBrandLogo())
            ->darkModeBrandLogo(fn (): ?string => self::resolveBrandLogo(dark: true))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): string => self::resolveFavicon())
            // Global search. The provider is ours (see the class for why) —
            // Filament's stock one has no floor on the query length, so a single
            // keystroke fanned out ~35 full table scans, and it ordered categories
            // by a per-resource integer nobody had set.
            //
            // ⌘K / Ctrl+K because that is what every tool an operator already has
            // open uses for the same gesture. The suffix renders the binding
            // inside the field, which is the only thing that makes a keyboard
            // shortcut discoverable to someone who never reads release notes.
            //
            // 400ms rather than Filament's 500ms: with the length floor and a
            // 5-row cap per resource, the query is cheap enough to run sooner,
            // and the gap between "typed" and "answered" is what this feels like.
            ->globalSearch(AtriomGlobalSearchProvider::class)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->globalSearchDebounce('400ms')
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
            // THE SIDEBAR IS ONE FILE: App\Support\Navigation. Replacing Filament's auto-assembly
            // with an explicit builder is what makes the sidebar reviewable — array order is
            // sidebar order, so there are no sort integers left to collide and no group that can be
            // referenced by a screen without being declared here (thirteen accounting pages were,
            // and the whole reporting section floated at the bottom as a result).
            //
            // Nothing about ACCESS moves: each entry splices in the screen's own
            // getNavigationItems(), which is empty when its module is off or the operator lacks the
            // permission, and Filament drops a group with nothing visible left in it.
            //
            // The risk this trades for is omission — a screen missing from the registry is missing
            // from the sidebar — which is why NavigationConformanceTest discovers every resource
            // and page on disk and then RENDERS this to check they all came out.
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => Navigation::build($builder))
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
    protected static function resolveBrandLogo(bool $dark = false): ?string
    {
        $tenant = Filament::getTenant();

        return PanelBranding::logo($tenant instanceof Asset ? $tenant : null, $dark);
    }

    protected static function resolveFavicon(): string
    {
        $tenant = Filament::getTenant();

        return PanelBranding::favicon($tenant instanceof Asset ? $tenant : null);
    }

    /**
     * The active property's colour, as a `<style>` block overriding Filament's `--primary-*`.
     *
     * The palette derivation lives in {@see PanelBranding} because the tenant portal skins itself
     * the same way; what stays here is the panel's own answer to WHICH property, which is a
     * different question on each panel — an explicit switcher choice here, a derivation there.
     */
    protected static function renderPerTenantThemeOverride(): string
    {
        $tenant = Filament::getTenant();

        return PanelBranding::themeOverride($tenant instanceof Asset ? $tenant : null);
    }
}
