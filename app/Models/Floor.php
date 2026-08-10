<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

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

    /** What an operator reads: "G — Ground floor", or just the code where no name was given. */
    public function label(): string
    {
        return $this->name ? "{$this->code} — {$this->name}" : $this->code;
    }
}
