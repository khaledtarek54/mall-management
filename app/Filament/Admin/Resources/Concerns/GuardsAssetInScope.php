<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Support\TenantScope;

/**
 * Server-side write guard for property isolation.
 *
 * A resource whose form exposes a client-editable `asset_id` (the Select is
 * enabled in "All Properties" mode, where its value is client-supplied) must
 * re-validate the submitted property against the current user's visible set on
 * BOTH create and edit — otherwise a property-restricted user could tamper the
 * id and write into another property's books.
 *
 * `TenantScope::visibleAssetIds()` returns null for portfolio users
 * (super_admin / owners / unconstrained), so the guard is a no-op for them and
 * only bites a restricted user submitting an out-of-scope (or null/consolidated)
 * property.
 *
 * Wire it from the Create/Edit page's mutate hook:
 *
 *     protected function mutateFormDataBeforeCreate(array $data): array
 *     {
 *         XResource::assertAssetInScope($data['asset_id'] ?? null);
 *         return $data;
 *     }
 *
 * The write-guard half of the property-isolation invariant. The read half is
 * `ScopesViaProperty` / `BypassesScopingOnAll`. See docs/PROPERTY-ISOLATION-PLAN.md.
 */
trait GuardsAssetInScope
{
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
