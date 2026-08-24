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

    /**
     * The same question asked of ONE record — "may the tenant see this?".
     *
     * Beside the scope and derived from the same `hiddenFor()` list rather than testing
     * `status !== 'draft'` at the call site, because that is how a second definition of "visible"
     * gets written: the scope excludes a set, and a hand-rolled predicate somewhere else would keep
     * only `draft` in mind and let the next hidden status through.
     *
     * Asked wherever something is PUSHED to a tenant (an email, a notification) rather than pulled
     * by one — a push has no query to narrow.
     */
    public function isVisibleToTenant(): bool
    {
        return ! in_array($this->status, TenantVisibility::hiddenFor($this->getTable()), true);
    }
}
