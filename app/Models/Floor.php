<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use App\Support\Occupancy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A floor of a property — B2, B1, G, M, 1, 2 — defined once and then SELECTED.
 *
 * **Why a register and not a column.** A floor is property-level data with perhaps eight rows, and
 * everything that stands on it needs the same list: units, and now parking bays. The column this
 * replaced (`units.floor_level`) asked every unit to repeat the same ordinal and left the label free
 * text, so "G" and "Ground" stayed two different floors to anything that grouped. A select kills
 * that at the source instead of normalising after it.
 *
 * **`level` is the ordinal, and it lives here once.** Basement negative, ground 0, upwards positive
 * — so B1 sorts before G, and 2 before 10, without raw SQL in any screen.
 */
#[DeletableWhenUnused(blockedBy: ['units', 'rentableItems'], instead: 'rename or re-order the floor — a floor that holds space is part of the property record')]
// a floor belongs to one building; code and level unique per property
#[PropertyOwned]
class Floor extends Model
{
    use HasFactory, LogsActivity, RefusesDeletionWhenReferenced;

    protected $fillable = ['asset_id', 'code', 'name', 'level'];

    protected $casts = ['level' => 'integer'];

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * A renamed floor re-folds the units standing on it.
     *
     * `Unit::searchTextSources()` quotes this floor's CODE — the one relation hop the search policy
     * allows, so an operator can narrow "ground A-1" the way they say it. The borrowed value has to
     * be pushed down by its OWNER when it changes, because the borrower cannot know it did.
     *
     * Until 2026-08-18 nothing did. The remedy was a docblock telling whoever renamed a floor to run
     * `atriom:rebuild-search` — but the code is edited through an ordinary EditAction on the
     * property's floors list, a screen that says nothing about search, so the old code stayed frozen
     * into every unit blob on the floor: the new name found nothing, the retired one still worked,
     * and no error connected the two. A documented manual remedy for a UI-triggerable action is a
     * remedy that does not exist.
     *
     * Scoped two ways, deliberately: only when `code` actually changed, and only over this floor's
     * own units — renaming one floor must not rewrite a property's every blob.
     */
    protected static function booted(): void
    {
        static::saved(function (self $floor): void {
            if (! $floor->wasChanged('code')) {
                return;
            }

            // Same discipline as `RebuildSearchIndex`, for its reasons: `refreshSearchText()`
            // ASSIGNS without saving, the write is skipped when the fold is unchanged, and it is
            // saved quietly with timestamps off — re-deriving a denormalized column is not
            // something that happened to the unit. A moved `updated_at` would reorder every
            // "recently changed" list in the system, and an activity log full of index rewrites is
            // one nobody reads. Soft-deleted units are included: they stay searchable via
            // `withTrashed()` elsewhere, so a stale blob would strand them too.
            $floor->units()->withTrashed()->cursor()->each(function (Unit $unit): void {
                $before = $unit->getAttribute('search_text');
                $unit->refreshSearchText();

                if ($unit->getAttribute('search_text') === $before) {
                    return;
                }

                $unit->timestamps = false;
                $unit->saveQuietly();
            });
        });
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /** @return HasMany<RentableItem, $this> */
    public function rentableItems(): HasMany
    {
        return $this->hasMany(RentableItem::class);
    }

    /**
     * The floor's leasable area, and how much of it is let.
     *
     * **No column, and no decision needed.** Per-floor GLA was flagged as the thing that might one
     * day justify promoting `Floor` to an entity — but it already IS one, and the figure is a sum
     * over the units standing on it. Storing it would be a second truth about the same square
     * metres, drifting the first time a unit is re-measured.
     *
     * Shares `App\Support\Occupancy`, so a floor cannot disagree with the property or the
     * dashboard about what "occupied" means. Rentable items never appear: a parking bay is not a
     * unit (docs/benchmarks/yardi/09-yardi-space-and-parking.md).
     *
     * @return array{occupied_sqm: float, total_sqm: float, pct: float|null}
     */
    public function areaFigures(): array
    {
        return Occupancy::forUnits(Unit::where('floor_id', $this->id));
    }

    /** What an operator reads: "G — Ground floor", or just the code where no name was given. */
    public function label(): string
    {
        return $this->name ? "{$this->code} — {$this->name}" : $this->code;
    }

    /** Named log — see RentableItem::getActivitylogOptions() for why this is not optional. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'code', 'name', 'level'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('floor');
    }
}
