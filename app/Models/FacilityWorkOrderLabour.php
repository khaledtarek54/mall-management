<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * ساعات عمل — hours reported against a work order.
 *
 * **The primitive that made in-house work cost zero.** Parts were costed and posted, contractor
 * work was costed and posted, and the operator's own technicians were captured nowhere — so
 * internal work was free on every report, insourcing always looked cheap, and every outsourcing
 * decision was wrong by the whole wage bill. Maximo §5.
 *
 * ## Cost is a CONSEQUENCE of reporting time, never a number anyone types
 *
 * Nobody is asked "what did this job cost". They are asked "how long did it take, and who did it" —
 * a question a technician can answer truthfully — and the rate turns it into money. A hand-typed
 * cost is a guess with a decimal point.
 *
 * ## The rate is frozen at entry
 *
 * Same rule as every other rate in this system: a rise in the trade's standard rate must not
 * silently re-price work done last March. {@see rateFor} resolves it once, on write.
 *
 * **A null rate is deliberate and visible.** A trade with no `standard_hourly_rate` produces hours
 * with no cost — the hours still count, the money is visibly missing, and the operator can see
 * which trade needs a rate. A default rate would produce a number that looks computed and is
 * invented, which is the failure this design exists to avoid.
 *
 * ## This does NOT post to the general ledger
 *
 * The wage is already there, via `Payroll` → `salaries_expense`. These rows **allocate** an
 * already-posted cost to the job that consumed it; they do not create one. A report that adds
 * payroll and work-order labour together double-counts. See the migration's docblock.
 */
#[DeletionAllowed(reason: 'a mis-keyed timesheet line is corrected by deleting it — nothing posted, and the work order simply recomputes. The hours are evidence of effort, not a financial document')]
#[PropertyOwned(via: 'workOrder')]
class FacilityWorkOrderLabour extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'facility_work_order_labour';

    protected $fillable = [
        'facility_work_order_id', 'trade_id', 'user_id', 'worked_on',
        'hours', 'hourly_rate', 'cost', 'notes', 'recorded_by_user_id',
    ];

    protected $casts = [
        'worked_on' => 'date',
        'hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $line) {
            // Default the craft to the job's own trade — the common case — but let a row state its
            // own: an electrician helping on an HVAC job is real, and forcing the job's trade onto
            // their hours would misreport the cost of both trades.
            $line->trade_id ??= $line->workOrder?->trade_id;

            // Resolved ONCE, on write. Re-reading it later would let a rate change rewrite history.
            $line->hourly_rate ??= self::rateFor($line->trade_id);

            $line->cost = $line->hourly_rate === null
                ? null
                : round((float) $line->hours * (float) $line->hourly_rate, 2);
        });

        // The work order is the cost object, and `recomputeCosts()` is its single source of truth —
        // so every channel that changes what a job cost calls it, exactly as every AR settlement
        // channel calls `Invoice::recomputeTotals()`.
        static::saved(fn (self $line) => $line->workOrder?->recomputeCosts());
        static::deleted(fn (self $line) => $line->workOrder?->recomputeCosts());
        static::restored(fn (self $line) => $line->workOrder?->recomputeCosts());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'work_order_labour');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FacilityWorkOrder::class, 'facility_work_order_id');
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** The craft rate in force, or null when the operator has not set one for that trade. */
    public static function rateFor(?int $tradeId): ?float
    {
        if ($tradeId === null) {
            return null;
        }

        $rate = Trade::query()->whereKey($tradeId)->value('standard_hourly_rate');

        return $rate === null ? null : (float) $rate;
    }
}
