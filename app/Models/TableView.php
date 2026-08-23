<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named filter/sort state for a resource list — see the migration for why it exists.
 *
 * The rule worth restating here: **a saved view is a bookmark, not a capability.** Applying one
 * navigates to a URL, and the list re-scopes every filter it is handed exactly as it does for a
 * hand-typed one. Nothing about saving or sharing a view widens what anyone may see.
 */
#[DeletionAllowed(reason: 'preference: a saved filter/sort state for a resource list, owned by the operator who saved it — same reasoning as SavedReport above')]
// the same, for a resource LIST: a property named in its filters is re-clamped by the list's own getEloquentQuery() on open
#[PortfolioShared]
class TableView extends Model
{
    protected $fillable = ['resource', 'name', 'state', 'user_id', 'is_shared'];

    protected $casts = [
        'state' => 'array',
        'is_shared' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Views this user may see on a list: their own, plus anything published to the team. */
    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('user_id', $userId)
            ->orWhere('is_shared', true));
    }

    /** Only this user's OWN views — the set they may rename or delete. */
    public function scopeOwnedBy(Builder $query, ?int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Which columns this view was saved showing — `name => isToggled`, or `[]` if it stated none.
     *
     * Deliberately NOT part of {@see queryParameters()}: a column layout is far too big for a query
     * string, and Filament does not bind one to the URL. It travels as `?tableView={id}`, so a saved
     * view is still a LINK — which is what makes a shared one show a colleague the same columns.
     *
     * Only the toggles are stored, never the labels or the hidden flags that sit beside them in
     * Filament's own state. Those are re-derived from the VIEWER's table every time
     * ({@see \Filament\Tables\Concerns\HasColumnManager::syncTableColumnStateItemAttributes()}
     * overwrites `label`, `isToggleable` and `isHidden` from the current default state), so storing
     * them would be storing a stale snapshot of somebody else's screen. It is also what makes a
     * shared view safe: the layout is rebuilt from what the reader may already see, so a view saved
     * by someone with wider rights cannot turn on a column their colleague's table does not have.
     *
     * Values are cast rather than trusted — this is JSON written by one version of the feature and
     * read by another, the same reasoning that makes `queryParameters()` an allowlist.
     *
     * @return array<string, bool>
     */
    public function columnState(): array
    {
        $columns = ($this->state ?? [])['columns'] ?? null;

        if (! is_array($columns)) {
            return [];
        }

        $state = [];

        foreach ($columns as $name => $isToggled) {
            if (is_string($name) && $name !== '') {
                $state[$name] = (bool) $isToggled;
            }
        }

        return $state;
    }

    /**
     * The query string this view reopens, restricted to the four keys a Filament list page binds.
     *
     * Anything else in `state` is dropped rather than passed through: the column is JSON written
     * by an earlier version of this feature and read by a later one, so treating it as an
     * allowlist is what keeps a stale key from becoming a query parameter nobody validates.
     *
     * @return array<string, mixed>
     */
    public function queryParameters(): array
    {
        $state = $this->state ?? [];

        return array_filter([
            'filters' => $state['filters'] ?? null,
            'sort' => $state['sort'] ?? null,
            'search' => $state['search'] ?? null,
            'tab' => $state['tab'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
