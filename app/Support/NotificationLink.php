<?php

namespace App\Support;

use App\Filament\Admin\Pages\NotificationCenter;
use App\Models\Asset;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\Channels\BellChannel;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Throwable;

/**
 * **Turns a {@see NotificationTargets} row + a reader into one URL, or nothing.**
 *
 * Everything hard about this lives in one sentence: the link is built where nobody is signed in.
 * Almost every notification here originates in a scheduled command or a queued job —
 * `billing:scan-overdue-invoices`, `requests:scan-sla-breaches`, `accounting:sync-ledger` — so at
 * build time there is no current panel, no current property, and no `Auth::user()`. Three things
 * follow, and each is a bug this class exists to prevent:
 *
 *  1. **The panel must be passed, never inferred.** `Resource::getUrl()` falls back to
 *     `Filament::getCurrentPanel()`; in a console run that is the default panel (`admin`), so a
 *     tenant-facing notification would quietly be handed an `/admin` URL it can never open. We
 *     pass `panel:` explicitly, chosen from the notifiable's class — `User` → admin,
 *     `Tenant`/`TenantUser` → portal — and a resource is only ever asked for a URL in the panel
 *     that actually owns it.
 *  2. **The property must be passed, never inferred.** `/admin` is tenanted, so its routes need a
 *     property slug and `Filament::getTenant()` is null out here. We derive it from the record
 *     itself, through {@see PropertyIsolation::owned()} — the registry that already knows every
 *     model's route to its `asset_id`, whether direct or via `lease.unit`. Nothing is guessed.
 *  3. **Authorization is checked against the READER, not the session.** `Resource::canView()` is
 *     no use here: it reads `Auth::user()`, which is null, so it would answer "no" for everyone
 *     and every link would vanish. We ask the recipient object directly.
 *
 * When any of that cannot be satisfied the answer is `null` and the caller falls back to the
 * notification centre. A link that 404s (wrong property) or 403s (no permission) is worse than no
 * link: it reads as a broken system rather than as a boundary.
 *
 * @see NotificationTargets  the registry of destinations
 * @see BellChannel  the single seam that calls this
 */
final class NotificationLink
{
    /**
     * Home property per operator, memoised for the life of the process. A single sweep
     * (`vendors:scan-document-expiry`) notifies the same handful of operators once per expiring
     * document, and this would otherwise re-query the asset_user + asset_owner join every time.
     *
     * @var array<int, ?int>
     */
    private static array $homeAssetIds = [];

    /** The panel a reader signs into. Null for anything that is not a bell audience. */
    public static function panelFor(object $notifiable): ?string
    {
        return match (true) {
            $notifiable instanceof User => 'admin',
            $notifiable instanceof TenantUser, $notifiable instanceof Tenant => 'portal',
            default => null,
        };
    }

    /**
     * The deep link for this notification and this reader, or null when there is no destination
     * they could actually open.
     *
     * @param  class-string  $notification  the CLASS, not an instance: the destination is a property
     *                                      of the kind of alert, and a backfill working over stored
     *                                      rows has only the class name to go on.
     * @param  array<string, mixed>  $payload  the notification's own `toDatabase()` output — the
     *                                         record id is read from here rather than from the
     *                                         notification object, so the registry's `payload_key`
     *                                         is the single description of where the id lives.
     */
    public static function for(string $notification, object $notifiable, array $payload): ?string
    {
        $panel = self::panelFor($notifiable);

        if ($panel === null) {
            return null;
        }

        $destination = NotificationTargets::destination($notification, $panel);

        if ($destination === null) {
            return null;
        }

        [$target, $hop] = $destination;

        // Never let a URL failure take down the thing that was being notified about. A missing
        // route or a deleted parent is a reason to show no link, not to fail the payment that
        // raised the alert.
        try {
            return $panel === 'admin'
                ? self::adminUrl($notification, $notifiable, $payload, $target, $hop)
                : self::portalUrl($notification, $notifiable, $payload, $target, $hop);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The notification centre for this reader — the destination for everything with no record of
     * its own, so no bell entry is ever a dead end.
     */
    public static function centre(object $notifiable): ?string
    {
        try {
            return match (self::panelFor($notifiable)) {
                'admin' => ($asset = self::homeAsset($notifiable))
                    ? NotificationCenter::getUrl(panel: 'admin', tenant: $asset)
                    : null,
                'portal' => \App\Filament\Portal\Pages\NotificationCenter::getUrl(panel: 'portal'),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  class-string  $target
     */
    private static function adminUrl(
        string $notification,
        object $notifiable,
        array $payload,
        string $target,
        ?string $hop,
    ): ?string {
        if (! $notifiable instanceof User) {
            return null;
        }

        $record = self::resolveRecord($notification, $payload, $hop);

        // The property whose URL segment this link needs, in order of how well each answer knows:
        //   1. the record's own property, through the isolation registry;
        //   2. the property the payload names, when the record is a SHARED master but the ALERT is
        //      property-specific — a low-stock warning is about one mall's warehouse even though
        //      the SKU it names is portfolio-wide;
        //   3. the reader's home property, which is at least a context they can open it in.
        $asset = $record ? self::assetOf($record) : null;
        $asset ??= filled($payload['asset_id'] ?? null) ? Asset::find($payload['asset_id']) : null;
        $asset ??= self::homeAsset($notifiable);

        if (! $asset instanceof Asset) {
            return null;
        }

        // A property the reader is not assigned to would 404 at Filament's IdentifyTenant. That is
        // the exact "links conflict across panels" failure, one layer in.
        if (! $notifiable->canAccessTenant($asset)) {
            return null;
        }

        if (is_subclass_of($target, Page::class)) {
            return $record === null
                ? $target::getUrl(panel: 'admin', tenant: $asset)
                : null;
        }

        if (! self::mayView($notifiable, $target)) {
            return null;
        }

        return self::resourceUrl($target, $record, panel: 'admin', asset: $asset);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  class-string  $target
     */
    private static function portalUrl(
        string $notification,
        object $notifiable,
        array $payload,
        string $target,
        ?string $hop,
    ): ?string {
        $tenantId = $notifiable instanceof Tenant
            ? $notifiable->getKey()
            : $notifiable->tenant_id ?? null;

        if (! $tenantId) {
            return null;
        }

        $record = self::resolveRecord($notification, $payload, $hop);

        // The portal scopes every read to the signed-in tenant in `getEloquentQuery()`. Linking a
        // record outside that scope would land on Filament's "record not found", so we apply the
        // same rule here rather than discovering it on click.
        if ($record !== null && self::tenantIdOf($record) !== $tenantId) {
            return null;
        }

        if (is_subclass_of($target, Page::class)) {
            return $record === null ? $target::getUrl(panel: 'portal') : null;
        }

        return self::resourceUrl($target, $record, panel: 'portal', asset: null);
    }

    /**
     * `view` when the resource has one, else `edit`, else the index. Mirrors how Filament's own
     * global search picks a landing page, so a notification and a search hit for the same record
     * open the same screen.
     *
     * @param  class-string<resource>  $resource
     */
    private static function resourceUrl(string $resource, ?Model $record, string $panel, ?Asset $asset): ?string
    {
        if ($record === null) {
            return $resource::getUrl('index', panel: $panel, tenant: $asset);
        }

        $page = match (true) {
            $resource::hasPage('view') => 'view',
            $resource::hasPage('edit') => 'edit',
            default => null,
        };

        if ($page === null) {
            return $resource::getUrl('index', panel: $panel, tenant: $asset);
        }

        return $resource::getUrl($page, ['record' => $record->getKey()], panel: $panel, tenant: $asset);
    }

    /**
     * Load the record the notification is about, then follow the registry's hop if it declares
     * one (an owner statement's link opens the RUN that renders it).
     *
     * `withTrashed` where the model supports it: a notification about a record that has since been
     * soft-deleted should still open it — the page explains the state far better than a dead link.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function resolveRecord(string $notification, array $payload, ?string $hop): ?Model
    {
        $spec = NotificationTargets::record($notification);

        if ($spec === null) {
            return null;
        }

        [$model, $key] = $spec;
        $id = $payload[$key] ?? null;

        if (blank($id)) {
            return null;
        }

        $query = $model::query();

        if (method_exists($model, 'bootSoftDeletes') || in_array(
            SoftDeletes::class,
            class_uses_recursive($model),
            true,
        )) {
            $query->withTrashed();
        }

        $record = $query->find($id);

        if ($record === null) {
            return null;
        }

        return $hop === null ? $record : $record->{$hop};
    }

    /**
     * The property a record belongs to, resolved through the isolation registry rather than by
     * guessing at an `asset_id` column — `Invoice` reaches its property via `lease.unit`, and half
     * a dozen other models are indirect the same way.
     */
    private static function assetOf(Model $record): ?Asset
    {
        if ($record instanceof Asset) {
            return $record;
        }

        if (! PropertyIsolation::isOwned($record::class)) {
            return null;   // a shared master — it has no property of its own
        }

        $node = $record;

        foreach (array_filter(explode('.', (string) PropertyIsolation::linkageFor($record::class))) as $relation) {
            $node = $node?->{$relation};

            // A to-many hop (Payment → invoices → lease.unit): any of them names the same
            // property, because a payment cannot span two malls.
            if ($node instanceof Collection) {
                $node = $node->first();
            }

            if ($node === null) {
                return null;
            }
        }

        $assetId = $node?->asset_id;

        return $assetId ? Asset::find($assetId) : null;
    }

    /** The tenant a portal-visible record belongs to — the same two paths the portal itself scopes on. */
    private static function tenantIdOf(Model $record): ?int
    {
        if (isset($record->tenant_id)) {
            return (int) $record->tenant_id;
        }

        // TenantSalesDeclaration reaches its tenant through the lease it declares against.
        $tenantId = $record->lease?->tenant_id ?? null;

        return $tenantId ? (int) $tenantId : null;
    }

    /**
     * Whether this operator may open the resource at all — asked of the USER, not of the session,
     * because there is no session. Mirrors `RoleGatedActions::hasPermission('view')`: the module
     * must be switched on and the reader must hold `{module}.view`.
     *
     * @param  class-string<resource>  $resource
     */
    private static function mayView(User $user, string $resource): bool
    {
        $module = method_exists($resource, 'permissionModuleKey')
            ? $resource::permissionModuleKey()
            : null;

        // A resource outside the RBAC trait states no permission requirement; its own page still
        // gates on arrival.
        if (blank($module)) {
            return true;
        }

        return Modules::enabled($module) && $user->can("{$module}.view");
    }

    /**
     * The property an operator lands in when the record itself names none. Their first accessible
     * one — for a super_admin with no assignment, the first real property in the portfolio.
     */
    private static function homeAsset(User $user): ?Asset
    {
        $id = self::$homeAssetIds[$user->getKey()] ??= $user
            ->getTenants(Filament::getPanel('admin'))
            ->first()?->getKey();

        return $id ? Asset::find($id) : null;
    }

    /** Test seam — the memoised home property must not survive into the next test's fixtures. */
    public static function flushCache(): void
    {
        self::$homeAssetIds = [];
    }
}
