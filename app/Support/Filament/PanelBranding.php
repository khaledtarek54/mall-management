<?php

namespace App\Support\Filament;

use App\Models\Asset;

/**
 * A property's branding, for whichever panel is asking.
 *
 * The admin panel has skinned itself per property since it gained tenancy: the mall's name in the
 * topbar, its logo, its favicon and its `primary_color` injected as the `--primary-*` palette. The
 * TENANT PORTAL had none of it — a hardcoded English `'Atriom · Tenant Portal'`, static logo and
 * favicon, no colour hook. A retailer signing into their landlord's portal saw the software vendor's
 * name, which is the one place white-labelling actually earns its keep.
 *
 * This is the shared half. Each panel still answers "WHICH property?" for itself, because the two
 * questions are genuinely different — the admin reads `Filament::getTenant()`, an explicit choice
 * the operator made in the property switcher; the portal DERIVES it from where the signed-in tenant
 * actually trades ({@see PortalBranding}), and answers null when that is not one place.
 *
 * The palette derivation lives here rather than in either provider because it was written once and
 * copying it would be a second thing to forget: a colour rule that drifts between two panels is a
 * mall whose portal is the wrong green.
 */
final class PanelBranding
{
    /**
     * A `<style>` block overriding Filament's `--primary-*` palette from an asset's colour.
     *
     * Filament 4's `->colors()` is evaluated once at panel boot, so per-request skinning has to go
     * through a render hook. Filament's compiled CSS uses these variables directly (e.g.
     * `background-color: var(--primary-600)`), so the values must be complete COLOURS, not RGB
     * triplets. The hex is pinned at the 500 shade and the rest derived with `color-mix()`.
     *
     * Empty string for no asset, the All-Properties pseudo-asset, no colour, or a malformed one —
     * an operator can type anything into that field and a broken `<style>` would take the panel's
     * chrome with it.
     */
    public static function themeOverride(?Asset $asset): string
    {
        if (! $asset instanceof Asset || $asset->isAllProperties() || ! $asset->primary_color) {
            return '';
        }

        $hex = '#'.ltrim($asset->primary_color, '#');

        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return '';
        }

        return <<<HTML
<style>
:root {
    --primary-50:  color-mix(in oklab, {$hex} 6%,  white);
    --primary-100: color-mix(in oklab, {$hex} 12%, white);
    --primary-200: color-mix(in oklab, {$hex} 24%, white);
    --primary-300: color-mix(in oklab, {$hex} 40%, white);
    --primary-400: color-mix(in oklab, {$hex} 65%, white);
    --primary-500: {$hex};
    --primary-600: color-mix(in oklab, {$hex} 88%, black);
    --primary-700: color-mix(in oklab, {$hex} 70%, black);
    --primary-800: color-mix(in oklab, {$hex} 55%, black);
    --primary-900: color-mix(in oklab, {$hex} 40%, black);
    --primary-950: color-mix(in oklab, {$hex} 25%, black);
}
</style>
HTML;
    }

    /**
     * The asset's own logo, else the platform wordmark for the panel's current mode.
     *
     * **The fallback differs by panel, because the question does.** In the ADMIN panel the reader is
     * the operator's own staff and the Atriom wordmark is the product they are using, so a mall with
     * no logo keeps it — that is a deliberate choice with its own tests. In the PORTAL it is the
     * one thing white-labelling exists to avoid, so {@see PortalBranding::logo()} answers null there
     * and lets Filament fall through to the mall's NAME: `logo.blade.php` renders `{{ $brandName }}`
     * only in the `@else`.
     *
     * An explicit light/dark variant rather than the auto-adapting `atriom-logo.svg`: that file
     * keys off the OS `prefers-color-scheme`, which desyncs from Filament's own in-app toggle
     * (light panel + dark OS rendered a cream wordmark on a white dashboard).
     */
    public static function logo(?Asset $asset, bool $dark = false): ?string
    {
        if ($asset instanceof Asset && ! $asset->isAllProperties() && ($logo = $asset->logoUrl())) {
            return $logo;
        }

        return self::platformLogo($dark);
    }

    public static function platformLogo(bool $dark = false): string
    {
        return asset($dark ? 'images/atriom-logo-dark.svg' : 'images/atriom-logo-light.svg');
    }

    public static function favicon(?Asset $asset): string
    {
        if ($asset instanceof Asset && ! $asset->isAllProperties() && ($favicon = $asset->faviconUrl())) {
            return $favicon;
        }

        return asset('atriom-favicon.svg');
    }
}
