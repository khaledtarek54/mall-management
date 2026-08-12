<?php

namespace App\Providers;

use App\Listeners\LogBackupFailures;
use App\Models\Lease;
use App\Notifications\Channels\BellChannel;
use App\Notifications\Channels\PushChannel;
use App\Observers\LeaseObserver;
use App\Services\Eta\Signing\EtaDocumentSigner;
use App\Services\Eta\Signing\UnsignedEtaSigner;
use App\Services\Paymob\PaymobClient;
use App\Services\Push\FcmPushSender;
use App\Services\Push\NullPushSender;
use App\Services\Push\PushSender;
use App\Settings\IntegrationsSettings;
use App\Support\ActivityVocabulary;
use App\Support\Filament\AuthorizedAction;
use App\Support\Filament\LocalizedNotification;
use App\Support\LedgerRealtimeSync;
use App\Support\TableDefaults;
use App\Support\ValueSets;
use Filament\Actions\Action as FilamentAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
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

        // The activity log's vocabulary MUST be one instance per request, because its whole
        // performance story is a cache: `preloadReferences()` resolves a page's foreign keys in
        // one query per table, and every Changes cell then reads that cache. The renderer is
        // built per cell via `app()`, so with the default (transient) binding each cell would get
        // a fresh, empty vocabulary — the preload would resolve a page and then be thrown away,
        // and the N+1 it exists to prevent would happen anyway. Nothing would look wrong.
        $this->app->singleton(ActivityVocabulary::class);

        // `->authorize()` on a Filament action is the SAME layer as `visible()` — both fold into
        // `isHidden()`/`isDisabled()` — and `Action::call()` checks nothing. So the second layer the
        // codebase documents did not exist for the 76 write actions that gate with `->authorize()`
        // alone. `Action::make()` resolves through the container, which is the one seam that fixes
        // all of them at once. See App\Support\Filament\AuthorizedAction.
        $this->app->bind(FilamentAction::class, AuthorizedAction::class);

        // Every bell notification gets a panel-correct "Open …" link. Laravel resolves the
        // `database` channel through the container, so this one binding reaches all 36 notification
        // classes — and every one added after it — instead of an `actions` key being remembered 36
        // times. Same seam as the AuthorizedAction bind above. See App\Notifications\Channels\BellChannel.
        $this->app->bind(DatabaseChannel::class, BellChannel::class);

        // …and read back in the READER's language rather than in whichever one was current when the
        // alert was raised (a scheduled command's `config('app.locale')`, or the sender's session).
        // `Notification::make()` resolves through the container and Filament's own `fromArray()`
        // re-dispatches to the child class, so this one binding reaches every bell render path.
        $this->app->bind(FilamentNotification::class, LocalizedNotification::class);

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

            return new NullPushSender;
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

        // Absolute URLs must be https in production. TLS terminates at the proxy, so PHP sees a
        // plain http request and every route()/url() call — the tenant payment link, password
        // resets for /admin, /portal and the mobile API, the Paymob return URL, the "Open Atriom"
        // button in an emailed alert — was built with http://.
        //
        // Forced rather than inferred from X-Forwarded-Proto on purpose: trusting that header
        // means trusting whatever sits in front of the app to set it, and getting it silently
        // wrong looks like nothing until a tenant clicks a payment link on a public network.
        // Proxy trust is configured separately (bootstrap/app.php) for the client IP.
        //
        // HSTS is already sent by SecurityHeaders, but HSTS only protects a browser that has
        // already made ONE successful https visit — it does nothing for the first click on a link
        // in an email, which is exactly how payment links are opened.
        if (config('security.force_https')) {
            URL::forceScheme('https');
        }

        $this->applyIntegrationKillSwitches();

        // A failed backup must leave a trace that does not depend on BACKUP_ALERT_EMAIL being set.
        // With it unset — the shipped default — spatie's failure notification is routed to NO
        // channel at all, so a nightly failure was completely silent. See the listener.
        Event::subscribe(LogBackupFailures::class);

        // The app-layer replacement for the 62 DB-level enum columns freed on 2026-08-12
        // (2026_08_12_270000_free_every_remaining_db_enum_column). MySQL used to refuse an
        // out-of-set value outright; nothing else did, so dropping the enums without this would have
        // left every service, job, importer and console command unconstrained.
        //
        // A wildcard model event rather than a trait on all 39 models, for the reason that put
        // AuthorizedAction in the container: a guard that must never be missing belongs at one seam,
        // where the fortieth model is covered before anyone remembers it — not in N files where a
        // conformance test has to police whether someone did. See App\Support\ValueSets.
        Event::listen('eloquent.saving: *', function (string $event, array $payload): void {
            foreach ($payload as $model) {
                if ($model instanceof Model) {
                    ValueSets::guard($model);
                }
            }
        });

        Lease::observe(LeaseObserver::class);

        // Near-real-time GL posting (Phase 2). Every posting source dispatches a queued
        // SyncDocumentToLedger job AFTER COMMIT whenever it is saved / deleted / restored,
        // so its journal entry reconciles within seconds instead of waiting up to a day for
        // the scheduled accounting:sync-ledger sweep. The job re-runs the idempotent,
        // lock-safe LedgerPoster::sync, so it can't double-book and the daily sweep + weekly
        // --all still backstop everything. Gated by config so the test suite (which drives
        // sync/sweep explicitly for deterministic posting) isn't raced by the async job.
        if (config('accounting.realtime_ledger_sync')) {
            LedgerRealtimeSync::register();
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

        // Panel-wide table UX floor — filter/search/sort persistence, the
        // filter layout, striping, pagination sizes. Applied before each
        // resource's own configure(), so a resource can still override any of
        // it. See App\Support\TableDefaults for the reasoning per setting.
        TableDefaults::register();

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

    /**
     * The operator's runtime kill switch for Paymob + WhatsApp, folded into the
     * config the whole codebase already reads.
     *
     * **Two switches, deliberately asymmetric.** `.env` (`PAYMOB_ENABLED`) means
     * *"credentials are provisioned"* — a deploy-time fact. The stored setting at
     * /admin/settings means *"collect right now"* — an operator decision. They are
     * ANDed, so the UI toggle can only ever DISABLE. Turning it on cannot conjure
     * credentials, and an operator can stop card collection during a Paymob outage
     * or a fraud incident without an SSH session and a `config:clear`.
     *
     * **What this fixes.** Until 2026-08-05 the Settings page wrote
     * `IntegrationsSettings::$paymob_enabled` and *nothing read it*; every gate read
     * `config('integrations.paymob.enabled')` straight from env. An operator who
     * switched Paymob off in the UI saw it save, and the mall kept taking cards.
     * A kill switch that silently does nothing is worse than no kill switch — the
     * operator stops looking for another way to stop it. (Same bug on WhatsApp.)
     *
     * Reads the settings table only when config is already true, so a deployment
     * without Paymob pays nothing for this. Failures are swallowed on purpose: mid
     * `migrate:fresh` the settings table does not exist yet, and boot must not die
     * for a switch that only ever narrows an already-configured integration.
     *
     * Public so it can be exercised in isolation — re-running the whole boot()
     * would re-register observers and render hooks.
     */
    public function applyIntegrationKillSwitches(): void
    {
        // config key => the IntegrationsSettings property that can switch it off.
        $switches = [
            'integrations.paymob.enabled' => 'paymob_enabled',
        ];

        // Only an integration env has already enabled can be narrowed — and only
        // then is the settings table worth touching.
        $live = array_filter($switches, fn (string $configKey) => (bool) config($configKey), ARRAY_FILTER_USE_KEY);

        if ($live === []) {
            return;
        }

        try {
            $settings = $this->app->make(IntegrationsSettings::class);

            foreach ($live as $configKey => $property) {
                // The DB read happens on property ACCESS, not on make() — spatie loads
                // a settings class lazily. Both have to sit inside the try, or a missing
                // settings table takes the whole boot down.
                if (! $settings->{$property}) {
                    config([$configKey => false]);
                }
            }
        } catch (\Throwable) {
            // Settings unavailable (e.g. mid `migrate:fresh`) — leave env's value
            // standing. Deliberately fails OPEN: this switch only ever narrows an
            // integration whose credentials are already provisioned, and refusing to
            // boot the app over an unreadable toggle is the worse outcome.
        }
    }
}
