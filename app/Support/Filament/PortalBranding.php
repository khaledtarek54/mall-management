<?php

namespace App\Support\Filament;

use App\Enums\UnitOwnershipStatus;
use App\Models\Asset;
use App\Models\Tenant;
use App\Support\IssuingEntity;
use Illuminate\Support\Facades\Auth;

/**
 * Which mall's branding a signed-in tenant should see — and when the honest answer is "none".
 *
 * The admin panel asks `Filament::getTenant()`: an explicit choice the operator made in the property
 * switcher. The portal has no such switcher and no Filament tenancy, so the property has to be
 * DERIVED from where the signed-in retailer actually trades.
 *
 * ## The rule: exactly one, or the platform
 *
 * A tenant that trades in ONE mall sees that mall's name, logo, favicon and colour. A tenant with
 * shops in three sees Atriom — because branding their portal as one of the three would be a claim
 * about the other two, and a chain reading "Cairo Festival Mall" on a page listing invoices from
 * three malls is worse than reading nothing.
 *
 * This is the same rule {@see IssuingEntity::forViewScopedTo()} already applies to the
 * tenant-facing PDFs, deliberately: a statement that carries one mall's letterhead and a portal that
 * carries another's would be the same tenant told two things.
 *
 * ## Where "trades" comes from
 *
 * Active leases (through the unit — a lease is `#[PropertyOwned(via: 'unit')]`) **and** handed-over
 * unit ownerships, because a unit owner is a `tenants` row too (module 37) and pays a service charge
 * through this same portal. `handed_over` and not `contracted`, matching the state that bills: until
 * the keys change hands the operator still holds the unit and the owner has no mall to be shown.
 *
 * ## Memoised per request
 *
 * The panel asks four times per page — name, logo, dark logo, favicon — plus the theme hook. A
 * static is wrong (a queue worker or an octane process outlives the request); the container is the
 * per-request scope this codebase uses everywhere else for exactly this.
 */
final class PortalBranding
{
    private const MEMO = 'portal_branding.asset';

    /** The one mall this tenant trades in, or null for none, several, or nobody signed in. */
    public static function asset(): ?Asset
    {
        $key = self::memoKey();

        if (app()->has($key)) {
            // A ONE-ELEMENT ARRAY, not the asset itself. `Container::bound()` is
            // `isset($this->instances[$abstract])`, and `isset()` is FALSE for a stored null — so a
            // memo holding the answer this class exists for (the login page, and a chain trading in
            // several malls) never registered as memoised at all, and the panel re-ran the query
            // five times per render: brandName, brandLogo, darkModeBrandLogo, favicon, theme.
            return app($key)[0];
        }

        $asset = self::resolve();

        app()->instance($key, [$asset]);

        return $asset;
    }

    /**
     * Keyed on WHO is asking.
     *
     * The memo lives in the container's `instances`, which `forgetScopedInstances()` does not clear
     * between queued jobs — so an un-keyed memo would be a cross-request leak the day anything
     * outside the HTTP panel renders portal chrome. Under php-fpm the container dies with the
     * request and Octane is not installed, so this is a guard against the next caller rather than a
     * live bug; it costs one string concatenation.
     */
    private static function memoKey(): string
    {
        return self::MEMO.'.'.(Auth::guard('portal')->id() ?? 'guest');
    }

    private static function resolve(): ?Asset
    {
        // The login and password-reset screens have no user, and that is not a failure — a portal
        // nobody has signed into yet cannot know whose it is.
        $tenant = Auth::guard('portal')->user()?->tenant;

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $ids = $tenant->activeLeases()
            ->with('unit:id,asset_id')
            ->get()
            ->pluck('unit.asset_id')
            ->concat(
                $tenant->unitOwnerships()
                    ->where('status', UnitOwnershipStatus::HandedOver->value)
                    ->pluck('asset_id')
            )
            ->filter()
            ->unique()
            ->values();

        return $ids->count() === 1 ? Asset::find($ids->first()) : null;
    }

    /**
     * The topbar label.
     *
     * The mall's own name when there is one, exactly as the admin panel does — a retailer knows
     * which portal they signed into, and the software vendor's name is not the useful word there.
     * Otherwise the translated platform label, which is what `'Atriom · Tenant Portal'` should
     * always have been: an Arabic-reading retailer was shown an English string the rest of the panel
     * had a translation for.
     */
    public static function brandName(): string
    {
        return self::asset()?->name ?? __('portal.brand');
    }

    /**
     * The mall's logo, its NAME, or the platform wordmark — in that order.
     *
     * Null when the tenant's one mall has uploaded no logo, which is the default state of every
     * property. Filament's `logo.blade.php` renders `{{ $brandName }}` **only in the `@else`**, so
     * returning the platform wordmark there put ATRIOM in the topbar of a white-labelled portal and
     * left the mall's name in the `<title>` and an `alt` attribute — which is also what made this
     * feature's own end-to-end test a false pass.
     *
     * The wordmark IS right with no mall in play: the login page, and a chain whose portal spans
     * three properties. Nothing else is honest there.
     */
    public static function logo(bool $dark = false): ?string
    {
        $asset = self::asset();

        return $asset instanceof Asset
            ? $asset->logoUrl()
            : PanelBranding::platformLogo($dark);
    }

    public static function favicon(): string
    {
        return PanelBranding::favicon(self::asset());
    }

    public static function themeOverride(): string
    {
        return PanelBranding::themeOverride(self::asset());
    }

    /** Drop the memo — for tests that sign in as a second tenant inside one request. */
    public static function forget(): void
    {
        app()->forgetInstance(self::memoKey());
    }
}
