<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use App\Support\WorkingCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * إجازة — one day the mall's people are not at work, or are at work on different hours.
 *
 * Egypt's public holidays are ANNOUNCED, not computable: the two Eids move on the Hijri calendar
 * and are fixed by moon sighting, and a mid-week holiday is routinely shifted to the neighbouring
 * Thursday. So this is a register the operator keeps, refreshed once a year — the same shape as
 * {@see Trade} and {@see FailureCode}, and for the same reason.
 *
 * A row is read by {@see WorkingCalendar}, never by a service directly. `date` +
 * `asset_id` is the whole key: the property's own row wins, the portfolio-wide row (null
 * `asset_id`) applies to every mall that has not said otherwise.
 *
 * **Editing one does not re-time anything already promised.** Every SLA deadline is stamped onto
 * its work order when the job is raised, along with the clock it was promised on — so this register
 * decides what happens NEXT, never what a finished job should have been measured against.
 */
#[DeletionAllowed(reason: 'configuration: a date register nothing holds a foreign key to. Deleting a past holiday cannot re-time history — every deadline it affected was STAMPED onto the work order when the job was raised, and the clock it was promised on is frozen there too. Deletion is super_admin-only project-wide, and an operator correcting a wrongly-dated Eid edits it')]
// Null `asset_id` = a national holiday, which is the ordinary case. A property row overrides it.
#[PropertyOwned(portfolioRowsWhenNull: true)]
class Holiday extends Model
{
    use LogsActivity;

    /** Nobody is at work; the working clock does not run at all. */
    public const KIND_CLOSURE = 'closure';

    /** A working day on different hours — Ramadan is the case this exists for. */
    public const KIND_SHORT_DAY = 'short_day';

    /** @var array<int, string> */
    public const KINDS = [self::KIND_CLOSURE, self::KIND_SHORT_DAY];

    protected $fillable = [
        'asset_id',
        'date',
        'kind',
        'opens_at',
        'closes_at',
        'name_en',
        'name_ar',
        'is_active',
    ];

    protected $casts = [
        'date' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'kind' => self::KIND_CLOSURE,
        'is_active' => true,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** The reader's language, falling back to the other rather than to a blank cell. */
    public function label(): string
    {
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }

    public function isClosure(): bool
    {
        return $this->kind === self::KIND_CLOSURE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The rows that could apply to a property: its own, plus the portfolio-wide ones.
     *
     * Which of the two WINS is decided in {@see WorkingCalendar}, not here — a scope
     * that silently dropped the national row would make "this mall trades through Eid" impossible
     * to express, and one that dropped the property row would make it impossible to honour.
     */
    public function scopeFor(Builder $query, ?int $assetId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('asset_id')
            ->when($assetId !== null, fn (Builder $w) => $w->orWhere('asset_id', $assetId)));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'date', 'kind', 'opens_at', 'closes_at', 'name_en', 'name_ar', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('holiday');
    }
}
