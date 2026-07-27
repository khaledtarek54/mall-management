<?php

namespace App\Providers;

use App\Models\Lease;
use App\Observers\LeaseObserver;
use App\Notifications\Channels\PushChannel;
use App\Services\Eta\Signing\EtaDocumentSigner;
use App\Services\Eta\Signing\UnsignedEtaSigner;
use App\Services\Paymob\PaymobClient;
use App\Services\Push\FcmPushSender;
use App\Services\Push\NullPushSender;
use App\Services\Push\PushSender;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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

        // Push delivery is pluggable + off by default (NullPushSender). Bind the
        // real FCM sender only when push is enabled AND a credentials path is set,
        // so the app runs without any Firebase setup (the DB inbox + email still
        // deliver). Mirrors the EtaDocumentSigner pattern.
        $this->app->bind(PushSender::class, function ($app) {
            $cfg = $app['config']->get('integrations.push', []);

            if (($cfg['enabled'] ?? false) && ! empty($cfg['fcm']['credentials'])) {
                return new FcmPushSender($cfg['fcm']['credentials'], $cfg['fcm']['project_id'] ?? null);
            }

            return new NullPushSender();
        });

        // The /owner panel was removed (2026-07-27): owners are admin-panel RBAC users
        // with the `owner` role (read-only oversight scoped to their owned properties),
        // not a separate portal.
    }

    public function boot(): void
    {
        // Numbers are ALWAYS in Western/Latin digits (0-9), even in the Arabic
        // UI — the Laravel Number helper (and Filament ->money(), which uses it)
        // otherwise emits Arabic-Indic digits under the 'ar' locale. Carbon's
        // bundled 'ar' locale already uses Western digits for dates.
        Number::useLocale('en');

        Lease::observe(LeaseObserver::class);

        // Near-real-time GL posting (Phase 2). Every posting source dispatches a queued
        // SyncDocumentToLedger job AFTER COMMIT whenever it is saved / deleted / restored,
        // so its journal entry reconciles within seconds instead of waiting up to a day for
        // the scheduled accounting:sync-ledger sweep. The job re-runs the idempotent,
        // lock-safe LedgerPoster::sync, so it can't double-book and the daily sweep + weekly
        // --all still backstop everything. Gated by config so the test suite (which drives
        // sync/sweep explicitly for deterministic posting) isn't raced by the async job.
        if (config('accounting.realtime_ledger_sync')) {
            \App\Support\LedgerRealtimeSync::register();
        }

        $this->configureMailCatchAll();

        // Register the 'push' notification channel (FCM via the bound PushSender).
        // Always registered so a notification with 'push' in its via() resolves
        // even when push is disabled (the NullPushSender just no-ops).
        Notification::resolved(fn ($service) => $service->extend('push', fn ($app) => $app->make(PushChannel::class)));

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

    /**
     * Outside production, redirect EVERY outgoing email to MAIL_ALWAYS_TO instead
     * of its real recipient. Demo/seed data is full of fake tenant addresses
     * (@atriomwalk.test …); pointed at a live provider those all hard-bounce, burn
     * the daily sending quota, and cost the sending domain reputation.
     *
     * The production guard is deliberate, not defensive: a stray MAIL_ALWAYS_TO on
     * the live box would silently swallow every tenant's invoice mail.
     *
     * Public so it can be exercised in isolation — re-running the whole boot()
     * would re-register observers and render hooks.
     */
    public function configureMailCatchAll(): void
    {
        if ($this->app->environment('production')) {
            return;
        }

        if (filled($to = config('mail.always_to'))) {
            Mail::alwaysTo($to);
        }
    }
}
