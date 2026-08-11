<?php

namespace App\Filament\Imports\Concerns;

use App\Models\Asset;
use App\Support\TenantScope;
use Closure;

/**
 * Resolve an asset by code, CLAMPED to the importing user's visible properties.
 *
 * An import bypasses the Create/Edit pages, which are the only place
 * `GuardsAssetInScope::assertAssetInScope()` runs — so without this clamp a restricted user can
 * upload a CSV row carrying another mall's code and create or overwrite that mall's data. That is
 * a cross-property **write** leak, and the property-isolation gate cannot see it, because no gate
 * covers the importers at all.
 *
 * `UnitImporter` has had this since it was written; `LeaseImporter` never did, and copied only the
 * `withoutGlobalScopes()` half — the half that removes the protection. Extracted here so the next
 * importer inherits the rule instead of re-deriving it, which is how the two diverged.
 *
 * `TenantScope::visibleAssetIds()` returning null means unrestricted (super_admin); otherwise the
 * asset must be in the visible set or this returns null and the row fails its validation rule.
 */
trait ResolvesVisibleAssetByCode
{
    protected static function resolveVisibleAsset(?string $code): ?Asset
    {
        if (! $code) {
            return null;
        }

        $asset = Asset::withoutGlobalScopes()->where('code', $code)->first();

        if (! $asset) {
            return null;
        }

        $visible = TenantScope::visibleAssetIds();

        return ($visible === null || in_array($asset->id, $visible, true)) ? $asset : null;
    }

    /**
     * The validation rule that fails a row whose property the importer may not write to.
     *
     * Returned as a closure rule so the refusal reaches the operator as a per-row error in the
     * failed-rows CSV, rather than as a silently skipped field.
     */
    protected static function assetInScopeRule(): Closure
    {
        return function (string $attribute, $value, Closure $fail): void {
            if (static::resolveVisibleAsset(is_string($value) ? $value : null) === null) {
                $fail(__('admin.validation.import_asset_out_of_scope'));
            }
        };
    }
}
