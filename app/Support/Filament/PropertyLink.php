<?php

namespace App\Support\Filament;

use App\Models\Asset;
use App\Support\PropertyIsolation;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * **A LINK AT A PROPERTY-SCOPED RECORD NAMES THAT RECORD'S OWN PROPERTY.**
 *
 * Every `/admin` route carries a `{tenant}` segment, and `Resource::getUrl()` fills it from
 * `Filament::getTenant()` — the SWITCHER. On most screens that is right by construction: the row
 * you are looking at belongs to the selected mall or you could not have opened the list. Two kinds
 * of screen break that assumption, and both produce the same failure:
 *
 *  - a page whose OWNER RECORD is portfolio-wide. `AssetResource` lists every mall on purpose
 *    (`$isScopedToTenant = false`), so its Units tab is showing mall B while the switcher says
 *    mall A. There the property is the tab's owner record, and the relation manager passes it
 *    directly — no resolution needed, and nothing per row.
 *  - a tab whose ROWS span properties. A tenant's compliance history is listed wherever they trade
 *    — `TenantViolationsRelationManager` and `TenantSalesDeclarationsRelationManager` were scoped
 *    by NOTHING when this was written (both narrow through `PropertyScope::apply()` since
 *    `2d0aad46`) — so the property is a fact about each ROW. That is what this class is for. Every
 *    tab's *Open* now resolves through `OpenRecordAction::urlFor()`, which calls this for any
 *    property-owned record whether or not the tab happens to be narrowed this week, so the answer
 *    does not depend on a scoping decision made in another file.
 *
 * The target resources are `ScopesToProperty`, and Filament resolves a route-bound record through
 * the resource's own scoped query, so a link naming the wrong mall resolves NO record: a **404**
 * from a row on screen, not a refusal anyone can act on. Reported from the panel as
 * `/admin/VP/units/13/edit`.
 *
 * **NULL RATHER THAN A FALLBACK TO THE SWITCHER.** That fallback IS the defect. It is
 * `NotificationLink`'s rule, arrived at for the identical reason and written there in full: *"A
 * link that 404s (wrong property) or 403s (no permission) is worse than no link: it reads as a
 * broken system rather than as a boundary."*
 *
 * `assetOf()` is that class's own resolver, EXTRACTED on its second real call site rather than
 * copied — two readings of "which property does this row belong to" are two answers waiting to
 * disagree, and this one is load-bearing for a URL where that one is load-bearing for a query.
 *
 * Bound `scoped()` in `AppServiceProvider`: a relation manager asks TWICE per row — once to build
 * the href and once to decide whether the control renders at all — and a real mall's units tab is
 * fifty rows of one property, so the memo is what stops a fix for a 404 shipping an N+1 in its
 * place, while a STATIC memo would be answered from whenever a `queue:work` daemon happened to
 * boot. Same rule as `NavigationItemMemo`, and for the same reason. Note the container resets
 * scoped instances per queued JOB but not per `artisan` command, so a long console run holds one
 * memo throughout — harmless here, since a mall's `code` is what is read and it does not move
 * under a running command.
 */
final class PropertyLink
{
    /** @var array<int, ?Asset> */
    private array $assets = [];

    /**
     * The URL of a record's own page, on the property that record belongs to.
     *
     * Null when the property cannot be resolved — an orphaned row, or a relation chain running
     * into a parent that is gone — which leaves the caller to render no link at all.
     *
     * @param  class-string  $resource
     * @param  array<string, mixed>  $parameters  merged over `record`, for a page that takes more
     */
    public static function to(string $resource, Model $record, string $page = 'edit', array $parameters = []): ?string
    {
        // THE WHOLE RESOLUTION IS INSIDE THE GUARD, not just the URL build — which is where
        // `NotificationLink::for()` puts it, and the half that was nearly lost in the extraction.
        // Walking a `#[PropertyOwned(via: …)]` chain is the part that can throw: a relation that no
        // longer exists is a `BadMethodCallException`, and a row's parent can simply be gone. A
        // throw here would take down the whole tab rather than drop one button.
        try {
            $asset = self::assetOf($record);

            if ($asset === null) {
                return null;
            }

            // NEVER OFFER A LINK INTO A MALL THE READER CANNOT ENTER. `IdentifyTenant` answers 404
            // for one, so the link would be a dead end wearing the look of a working control — and
            // the href itself would name another mall's slug on the page. This is the check
            // `NotificationLink::adminUrl()` makes against its own reader for the same reason; here
            // the reader is whoever is signed into the panel. With nobody signed in (a console run
            // rendering a table) there is no one to refuse, and the URL is built as before.
            $user = Filament::auth()->user();

            if ($user !== null && method_exists($user, 'canAccessTenant') && ! $user->canAccessTenant($asset)) {
                return null;
            }

            return $resource::getUrl($page, ['record' => $record, ...$parameters], tenant: $asset);
        } catch (Throwable) {
            // A missing route, an unroutable record or a broken chain is a reason to show no link,
            // never to take the list it sits in down with it.
            return null;
        }
    }

    /**
     * The property a record belongs to, resolved through the isolation registry rather than by
     * guessing at an `asset_id` column — `Invoice` is direct, `TenantRequest` reaches its property
     * via `unit`, `TenantSalesDeclaration` via `lease.unit`, and half a dozen others are indirect
     * the same way.
     */
    public static function assetOf(Model $record): ?Asset
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
            // Eloquent's Collection EXTENDS Support's, so this one test covers both. An earlier
            // draft named them separately and presented that as the difference from the original;
            // it was not a difference at all.
            if ($node instanceof Collection) {
                $node = $node->first();
            }

            if ($node === null) {
                return null;
            }
        }

        $assetId = $node?->asset_id;

        if (! $assetId) {
            return null;
        }

        $memo = app(self::class);
        $key = (int) $assetId;

        // `array_key_exists`, not `??=`: a MISS is worth memoising too. A dangling `asset_id`
        // resolves to null, and `??=` would re-query it once for every row on the page.
        if (! array_key_exists($key, $memo->assets)) {
            $memo->assets[$key] = Asset::find($key);
        }

        return $memo->assets[$key];
    }
}
