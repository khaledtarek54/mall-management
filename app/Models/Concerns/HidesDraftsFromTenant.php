<?php

namespace App\Models\Concerns;

use App\Support\TenantVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gives a document model the `visibleToTenant()` scope described by {@see TenantVisibility}.
 *
 * The scope goes on the QUERY, not on the relationship, on purpose: `Tenant::invoices()` is read
 * by admin services and the GL, which must see every row including drafts. Only the tenant-facing
 * readers narrow it, and they say so at the call site.
 *
 * It excludes rather than allowlists — `whereNotIn(hidden)` instead of `whereIn(visible)` — so a
 * status that exists in the table but not in `ValueSets` (a legacy row, an import) still reaches
 * the tenant. Losing a real document from someone's history is the worse failure of the two.
 */
trait HidesDraftsFromTenant
{
    public function scopeVisibleToTenant(Builder $query): Builder
    {
        $hidden = TenantVisibility::hiddenFor($this->getTable());

        return $hidden === [] ? $query : $query->whereNotIn($this->getTable().'.status', $hidden);
    }
}
