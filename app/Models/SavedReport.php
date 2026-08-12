<?php

namespace App\Models;

use App\Support\ReportCatalogue;
use Carbon\CarbonInterface;
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
    public const MONTHLY = 'monthly';

    public const WEEKLY = 'weekly';

    /** @var array<int, string> */
    public const FREQUENCIES = [self::MONTHLY, self::WEEKLY];

    protected $fillable = [
        'report', 'name', 'parameters', 'user_id', 'is_shared',
        'frequency', 'day_of_month', 'day_of_week', 'recipients', 'last_delivered_on',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_shared' => 'boolean',
        'recipients' => 'array',
        'last_delivered_on' => 'immutable_date',
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

    /**
     * Is this view due to be delivered on `$on`, and not already sent today?
     *
     * `last_delivered_on` is the idempotency key and it is a DATE. The command runs from the
     * scheduler and may run more than once in a day — a retry, a catch-up after downtime, two
     * workers — and a month-end pack that arrives three times is how an operator learns to filter
     * the sender.
     *
     * A monthly schedule set to the 31st fires on the last day of a short month rather than being
     * skipped: "the 31st" from an accountant means month end, and silently not sending in February
     * is the failure they would notice last.
     */
    public function isDueOn(CarbonInterface $on): bool
    {
        if ($this->frequency === null || blank($this->recipients)) {
            return false;
        }

        if ($this->last_delivered_on?->isSameDay($on)) {
            return false;
        }

        return match ($this->frequency) {
            self::WEEKLY => (int) $on->dayOfWeekIso === (int) ($this->day_of_week ?? 1),
            self::MONTHLY => (int) $on->day === min((int) ($this->day_of_month ?? 1), $on->daysInMonth),
            default => false,
        };
    }

    /** Views whose report still exists in the catalogue — one removed leaves its views orphaned. */
    public function scopeCatalogued(Builder $query): Builder
    {
        return $query->whereIn('report', collect(ReportCatalogue::REPORTS)->pluck('key')->all());
    }
}
