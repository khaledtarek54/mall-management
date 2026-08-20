<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * كود عطل — one problem, cause or remedy an engineer can record on a finished job.
 *
 * **The reliability primitive** (Maximo §7). Recording problem → cause → remedy on completion is
 * what makes MTBF, bad-actor analysis, repair-or-replace and warranty recovery answerable — and
 * what turns scenario S6 from "four unrelated successes" into "one problem, four remedies, nobody
 * has found the cause".
 *
 * Worth nothing on the day it ships and everything two years later, which is the argument for
 * shipping it EARLY rather than when somebody asks for the dashboard: a dashboard built before the
 * codes has nothing to read.
 *
 * ## Scoped by trade, not chained to a parent
 *
 * Maximo chains causes to problems. That chain is a matrix somebody must populate before anything
 * can be recorded, and an unpopulated matrix offers no codes — so nobody records anything and the
 * primitive is dead on arrival. Here {@see Trade} is the class (it already classifies
 * work orders, plans and machines, and a second taxonomy would be one more list to keep in step),
 * and a code with no trade is offered everywhere. See the migration docblock.
 */
#[DeletableWhenUnused(
    blockedBy: ['problemOrders', 'causeOrders', 'remedyOrders'],
    instead: 'deactivate it — a code recorded on a finished job is the dimension every reliability figure groups by, and deleting it strands that history',
)]
#[PortfolioShared] // a refrigerant leak is a refrigerant leak in every mall
class FailureCode extends Model
{
    use HasFactory, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    /** What was observed. */
    public const TYPE_PROBLEM = 'problem';

    /** Why it happened. */
    public const TYPE_CAUSE = 'cause';

    /** What was done about it. */
    public const TYPE_REMEDY = 'remedy';

    public const TYPES = [self::TYPE_PROBLEM, self::TYPE_CAUSE, self::TYPE_REMEDY];

    protected $fillable = ['code', 'type', 'trade_id', 'name_en', 'name_ar', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('failure_code')
            ->logOnly(['code', 'type', 'trade_id', 'name_en', 'name_ar', 'is_active', 'sort_order'])
            ->logOnlyDirty();
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function problemOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class, 'failure_problem_id');
    }

    public function causeOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class, 'failure_cause_id');
    }

    public function remedyOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class, 'failure_remedy_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The operator's own words, in the reader's language — a code is a ROW, not a translation key. */
    public function label(): string
    {
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }

    /**
     * The codes of one type that a job of this trade may record.
     *
     * A code with **no** trade is offered everywhere — that is what makes a useful starter set
     * possible before anyone has classified anything, and what stops a freshly-installed trade from
     * having an empty picker.
     *
     * `$keep` is offered whatever its state, for the same reason `Trade::options()` does it:
     * deactivating a code is the documented alternative to deleting one, and Filament validates a
     * Select with `Rule::in`, so without it a job carrying a retired code could not be edited at all.
     *
     * @return array<int, string>
     */
    public static function options(string $type, ?int $tradeId = null, ?int $keep = null): array
    {
        $options = static::query()
            ->where('type', $type)
            ->active()
            ->when($tradeId !== null, fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner->whereNull('trade_id')->orWhere('trade_id', $tradeId),
            ))
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (self $c): array => [$c->id => $c->label()]);

        if ($keep !== null && ! $options->has($keep) && ($retired = static::find($keep))) {
            $options->put($retired->id, $retired->label().' ⚠');
        }

        return $options->all();
    }
}
