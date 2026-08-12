<?php

namespace App\Models;

use App\Support\ReportCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named set of report parameters — see the migration for why it exists and what it does not do.
 *
 * The one rule worth restating here: **a saved view is a bookmark, not a capability.** Listing one
 * asks the report page's own `canAccess()`, and the report re-scopes every parameter it is handed.
 * Nothing about saving a view widens what its owner — or anyone they share it with — may see.
 */
class SavedReport extends Model
{
    protected $fillable = ['report', 'name', 'parameters', 'user_id', 'is_shared'];

    protected $casts = [
        'parameters' => 'array',
        'is_shared' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Views this user may see: their own, plus anything published to the team. */
    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('user_id', $userId)
            ->orWhere('is_shared', true));
    }

    /** Views whose report still exists in the catalogue — one removed leaves its views orphaned. */
    public function scopeCatalogued(Builder $query): Builder
    {
        return $query->whereIn('report', collect(ReportCatalogue::REPORTS)->pluck('key')->all());
    }
}
