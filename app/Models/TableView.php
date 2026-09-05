<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use App\Support\Filament\SavedColumnLayout;
use App\Support\Filament\TableViewDefaultMemo;
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
    /** Request-scoped memo for {@see defaultFor()}. */
    private const DEFAULT_MEMO = TableViewDefaultMemo::class;

    /**
     * Forget this request's resolved defaults.
     *
     * Called from both tables' write hooks: sharing, unsharing, deleting a view or moving the team
     * flag all change the answer for people who never touched anything.
     */
    public static function flushDefaultMemo(): void
    {
        app(self::DEFAULT_MEMO)->answers = [];
    }

    protected static function booted(): void
    {
        $flush = fn () => static::flushDefaultMemo();

        static::saved($flush);
        static::deleted($flush);
    }

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
     * Two tiers, and they answer two different questions:
     *
     *  1. **This person's own answer**, a row in `table_view_defaults`. A `table_view_id` is where
     *     they start; a NULL one is the explicit "no default for me", which is why the table
     *     exists — somebody with no view of their own previously had nowhere to record that and
     *     could not escape a team default at all.
     *  2. **The team's starting point** — a SHARED view its owner marked. Reached only when this
     *     person has stated nothing.
     *
     * A personal answer beats the team's, which is what stops a manager marking a team view from
     * silently overruling every colleague who had already chosen. It reads through `visibleTo`,
     * so a view that was later unshared quietly demotes its followers to the team default rather
     * than opening a list they may no longer see. A DELETED view takes the follower's row with it
     * (`cascadeOnDelete`), which reads as "has not chosen" — deliberately not as the stored NULL
     * that means "no default for me", or deleting a shared view would opt all of its followers out
     * of the team default as well.
     *
     * Ordered by key within the team tier so the answer is deterministic. `makeTeamDefault()`
     * keeps that tier to one view, which is safe now in a way it never was before — the flag no
     * longer doubles as anybody's personal preference, so there is nothing of somebody else's to
     * destroy — but a restored backup or a hand-edited row must still resolve the same way on
     * every request.
     */
    public static function defaultFor(string $resource, ?int $userId): ?self
    {
        if ($userId === null) {
            return null;
        }

        // Memoised for the request: every admin list asks this twice — once in the mount hook that
        // may redirect, once while building the saved-views menu — and this codebase treats
        // per-page panel cost as a standing concern. Keyed by list AND person so nothing can leak
        // between users in a queue worker or a test that swaps `actingAs`.
        $memo = app(self::DEFAULT_MEMO);
        $key = $resource.'|'.$userId;

        if (array_key_exists($key, $memo->answers)) {
            return $memo->answers[$key];
        }

        $stated = TableViewDefault::query()
            ->where('user_id', $userId)
            ->where('resource', $resource)
            ->first();

        if ($stated !== null) {
            // NULL is an ANSWER: they asked for the plain list.
            if ($stated->table_view_id === null) {
                return $memo->answers[$key] = null;
            }

            $view = static::query()
                ->whereKey($stated->table_view_id)
                ->where('resource', $resource)
                ->visibleTo($userId)
                ->first();

            if ($view !== null) {
                return $memo->answers[$key] = $view;
            }
        }

        return $memo->answers[$key] = static::query()
            ->where('resource', $resource)
            ->where('is_shared', true)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Make this the ACTOR's own landing view for this list.
     *
     * It writes THEIR row and touches nothing else — not this view, not its owner's preference,
     * not anyone else's. That is the whole point of the pivot: adopting a colleague's shared view
     * used to set a flag on their row, which made it the team's default for everybody and, on the
     * way past, cleared flags that were somebody's stated preference.
     *
     * Marking the TEAM's starting point is a different act with a different gate — see
     * {@see makeTeamDefault()} — because it is a decision about everyone's screen and belongs to
     * whoever published the view.
     */
    public function makeDefault(?int $actorId = null): void
    {
        $actorId ??= $this->user_id;

        TableViewDefault::query()->updateOrCreate(
            ['user_id' => $actorId, 'resource' => $this->resource],
            ['table_view_id' => $this->getKey()],
        );
    }

    /**
     * Put this operator back to following whatever the team does.
     *
     * DELETES the row, because absence is exactly that state — `defaultFor()` falls through to the
     * team tier when nobody has stated an answer. Distinct from {@see forgetDefaultFor()}, which
     * stores a NULL meaning "the plain list, whatever the team says"; two different answers, and
     * conflating them is the fault this table was created to end.
     *
     * Through the MODEL, never a query-builder `delete()`: a mass delete fires no model events, so
     * the request memo behind `defaultFor()` would keep answering with the row just removed — and
     * in Livewire the write and the re-render are one request, so the operator would see their old
     * answer immediately after changing it.
     */
    public static function followTeamDefaultFor(string $resource, int $userId): void
    {
        TableViewDefault::query()
            ->where('user_id', $userId)
            ->where('resource', $resource)
            ->get()
            ->each
            ->delete();
    }

    /**
     * Record that this operator wants NO default for this list.
     *
     * A stored NULL rather than a deleted row, deliberately: absence means "they have not chosen"
     * and falls through to the team's starting point, which is exactly what somebody pressing
     * "no default" is trying to get away from. This is the escape that did not exist.
     */
    public static function forgetDefaultFor(string $resource, int $userId): void
    {
        TableViewDefault::query()->updateOrCreate(
            ['user_id' => $userId, 'resource' => $resource],
            ['table_view_id' => null],
        );
    }

    /**
     * Make this shared view where the TEAM starts — the owner's decision, not a reader's.
     *
     * Only meaningful on a shared view: `defaultFor()` reads this tier for people who have stated
     * nothing, and an unshared row would be a default nobody but its owner could ever see, which
     * their own preference row already says better.
     *
     * Clears the flag from the list's other shared views, which is safe HERE in a way it never was
     * before: `is_default` no longer doubles as anybody's personal preference, so there is nothing
     * of somebody else's to destroy.
     */
    public function makeTeamDefault(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('resource', $this->resource)
                ->where('is_shared', true)
                ->whereKeyNot($this->getKey())
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
