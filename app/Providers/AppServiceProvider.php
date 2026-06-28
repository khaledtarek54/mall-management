<?php

namespace App\Providers;

use App\Listeners\LogAccessControlChange;
use App\Models\Lease;
use App\Observers\LeaseObserver;
use App\Providers\Filament\OwnerPanelProvider;
use App\Services\Eta\Signing\EtaDocumentSigner;
use App\Services\Eta\Signing\UnsignedEtaSigner;
use App\Services\Paymob\PaymobClient;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PaymobClient needs config-driven primitive args; teach the container
        // to build it through the fromConfig factory so controllers + actions
        // can typehint it directly.
        $this->app->singleton(PaymobClient::class, fn () => PaymobClient::fromConfig());

        // ETA document signing is pluggable. The default is a passthrough (no-op)
        // so mock/preprod plumbing works without a certificate; bind a real CAdES
        // signer here once the operator's signing certificate is provisioned.
        $this->app->bind(EtaDocumentSigner::class, UnsignedEtaSigner::class);

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
        // Numbers are ALWAYS in Western/Latin digits (0-9), even in the Arabic
        // UI — the Laravel Number helper (and Filament ->money(), which uses it)
        // otherwise emits Arabic-Indic digits under the 'ar' locale. Carbon's
        // bundled 'ar' locale already uses Western digits for dates.
        Number::useLocale('en');

        Lease::observe(LeaseObserver::class);

        // Audit user↔role grants (spatie RoleAttached/Detached) into the activity
        // log. Requires config('permission.events_enabled') = true.
        Event::subscribe(LogAccessControlChange::class);

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
