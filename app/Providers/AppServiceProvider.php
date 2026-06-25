<?php

namespace App\Providers;

use App\Models\Lease;
use App\Observers\LeaseObserver;
use App\Providers\Filament\OwnerPanelProvider;
use App\Services\Paymob\PaymobClient;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PaymobClient needs config-driven primitive args; teach the container
        // to build it through the fromConfig factory so controllers + actions
        // can typehint it directly.
        $this->app->singleton(PaymobClient::class, fn () => PaymobClient::fromConfig());

        // Owner portal is opt-in. Registering its panel provider only when the
        // feature flag is on keeps the /owner panel (routes + login) entirely
        // absent while disabled. Tests set OWNER_PORTAL_ENABLED=true so the
        // owner-panel suite still runs.
        if (config('features.owner_portal')) {
            $this->app->register(OwnerPanelProvider::class);
        }
    }

    public function boot(): void
    {
        Lease::observe(LeaseObserver::class);

        // Bulk delete is OFF by default across every Filament table — a
        // destructive multi-row action shouldn't be one mis-click. Most
        // resources gate it on RoleGatedActions::canDeleteAny() (opt back in
        // with `protected static bool $bulkDeletable = true;`); this backstop
        // hides any DeleteBulkAction that isn't explicitly gated (e.g. relation
        // managers). A table re-shows it with `->visible(...)`.
        DeleteBulkAction::configureUsing(fn (DeleteBulkAction $action) => $action->hidden());
        ForceDeleteBulkAction::configureUsing(fn (ForceDeleteBulkAction $action) => $action->hidden());

        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): string => Blade::render('@include("filament.language-switch")'),
        );
        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
            fn (): string => Blade::render('<div class="flex justify-center mb-4">@include("filament.language-switch")</div>'),
        );

        // "Powered by TRITECH" attribution across every panel. Filament renders
        // the FOOTER hook in both the main and the simple (login / password-
        // reset) layouts, so this single registration covers authenticated and
        // auth pages alike. Registered globally so admin, owner, and portal all
        // inherit it from one place.
        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            fn (): string => view('branding.powered-by')->render(),
        );
    }
}
