<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use App\Support\Filament\SavedColumnLayout;
use Filament\Tables\Concerns\HasColumnManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
    protected $fillable = ['resource', 'name', 'state', 'user_id', 'is_shared', 'is_default'];

    protected $casts = [
        'state' => 'array',
        'is_shared' => 'boolean',
        'is_default' => 'boolean',
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
     * The view this list should OPEN on for `$userId`, or null when they have not chosen one.
     *
     * **A personal default beats a team one.** An unshared default is a preference its owner stated
     * about their own screen; a shared one is a manager saying "this is where the team starts". If
     * the two disagree the person's own choice must win, or marking a team view would silently
     * overrule every colleague who had already set theirs.
     *
     * Ordered by key within each tier so the answer is deterministic — the write path allows only
     * one default per user per resource, but a restored backup or a hand-edited row must still
     * resolve to the same view on every request rather than whichever the database offers first.
     */
    public static function defaultFor(string $resource, ?int $userId): ?self
    {
        if ($userId === null) {
            return null;
        }

        $base = static fn (): Builder => static::query()
            ->where('resource', $resource)
            ->where('is_default', true);

        return $base()->where('user_id', $userId)->orderBy('id')->first()
            ?? $base()->where('is_shared', true)->orderBy('id')->first();
    }

    /**
     * Make this the ACTOR's default for its list, clearing whatever held the flag before.
     *
     * On the MODEL rather than in the action, because "at most one default per user per resource"
     * is a fact about these rows and there is no partial unique index to enforce it — see the
     * migration. Two callers writing the flag directly is how a user ends up with two defaults and
     * a list that opens on whichever one the database returns first.
     *
     * **THE ACTOR, NOT THE ROW'S OWNER (D3-04).** This cleared `where('user_id', $this->user_id)`
     * — the id on the ROW, which is the actor only when somebody marks their own view. A colleague
     * adopting a SHARED view therefore cleared the OWNER's flags: the author's list silently
     * stopped opening where they had set it, and nothing on either screen said so. It also left
     * the actor's OWN personal default standing, and a personal default WINS — so the button the
     * colleague had just pressed appeared to do nothing at all.
     *
     * Two clearings, because `is_default` answers at two tiers (see {@see defaultFor()}):
     *
     *  - always the ACTOR's own views, so their previous personal default gives way — this is what
     *    makes adopting a shared view actually land them on it;
     *  - and, when the view being marked is SHARED, any other shared default for this list,
     *    because "where the team starts" is one view and two of them resolve by row id.
     *
     * A view belonging to somebody else and never shared is unreachable here: the action resolves
     * through `visibleTo` before calling this.
     */
    public function makeDefault(?int $actorId = null): void
    {
        $actorId ??= $this->user_id;

        DB::transaction(function () use ($actorId): void {
            static::query()
                ->where('resource', $this->resource)
                ->whereKeyNot($this->getKey())
                ->where(function (Builder $q) use ($actorId): void {
                    $q->where('user_id', $actorId);

                    if ($this->is_shared) {
                        $q->orWhere('is_shared', true);
                    }

                })
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
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
     * ({@see HasColumnManager::syncTableColumnStateItemAttributes()}
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
        return SavedColumnLayout::togglesFrom($this->state);
    }

    /**
     * The order the columns were in when the view was saved, as a list of names.
     *
     * Separate from {@see columnState()} because they answer different questions — WHICH columns
     * show, and in WHAT ORDER — and because a view saved before columns were reorderable states the
     * first and not the second. An empty list means "the list's own order", which is what every
     * pre-existing row says.
     *
     * @return array<int, string>
     */
    public function columnOrder(): array
    {
        return SavedColumnLayout::orderFrom($this->state);
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
