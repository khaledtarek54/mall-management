<?php

namespace App\Models\Concerns\FacilityWorkOrder;

use App\Models\FailureCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The reliability primitives: what went wrong, and whether we have been here before.
 *
 * Maximo §7 (problem → cause → remedy) and ServiceChannel §4 (repeat visits). Both answer questions
 * about a MACHINE'S HISTORY rather than about this job, which is why they belong together and apart
 * from the job's own lifecycle.
 *
 * Extracted from `FacilityWorkOrder` on 2026-08-20 — see {@see TracksPmCompliance} for the reason.
 */
trait RecordsFailuresAndRepeats
{
    /** What was observed. {@see FailureCode} */
    public function failureProblem(): BelongsTo
    {
        return $this->belongsTo(FailureCode::class, 'failure_problem_id');
    }

    /** Why it happened. */
    public function failureCause(): BelongsTo
    {
        return $this->belongsTo(FailureCode::class, 'failure_cause_id');
    }

    /** What was done about it. */
    public function failureRemedy(): BelongsTo
    {
        return $this->belongsTo(FailureCode::class, 'failure_remedy_id');
    }

    /**
     * **Has this been fixed before, recently?** (ServiceChannel §4.)
     *
     * The highest-value cheap signal in retail FM: it identifies the fault that was never actually
     * fixed, and the contractor who keeps coming back to bill twice. Scenario S6 — the same
     * escalator handrail four times in five weeks, four invoices, and a register showing four
     * unrelated successes.
     *
     * **Same THING, not merely the same property.** A machine when the job names one; otherwise the
     * unit, because a shop is what a tenant reports about. Two jobs in the same mall are not a
     * repeat of each other and counting them so would make every busy property look like a failure.
     *
     * Trade-matched as well: an electrical fault and a plumbing fault in one shop are two problems,
     * not one recurring one.
     *
     * A FOLLOW-UP is excluded. `parent_work_order_id` says the operator already knows this job came
     * out of that one — it is a continuation somebody planned, not a fault that came back.
     *
     * Counted BEFORE this job, never after: the question is "did we already fix this?", and a later
     * visit is the next job's finding, not this one's.
     */
    public function scopeRepeatsOf(Builder $query, self $order, ?int $days = null): Builder
    {
        $days = $days ?? (int) config('facility.repeat_visit_days', 30);
        $since = CarbonImmutable::parse($order->created_at ?? now())->subDays($days);

        return $query
            ->whereKeyNot($order->getKey())
            ->where('status', '!=', 'cancelled')
            ->where('trade_id', $order->trade_id)
            ->whereNull('parent_work_order_id')
            ->when(
                $order->equipment_id !== null,
                fn (Builder $q) => $q->where('equipment_id', $order->equipment_id),
                // No machine named: fall back to the SHOP. Refuse to match on nothing — without
                // this guard a job with neither would "repeat" every other job in the trade.
                fn (Builder $q) => $order->unit_id === null
                    ? $q->whereRaw('1 = 0')
                    : $q->where('unit_id', $order->unit_id)->whereNull('equipment_id'),
            )
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $order->created_at ?? now());
    }

    /**
     * The same count for a LIST, in ONE query instead of one per row.
     *
     * `priorVisitCount()` is the definition; this is how a table reads it without an N+1 — the same
     * pairing as `ServicePlan::complianceRate()` and its count-based twin, and pinned by a test that
     * the two agree, because a badge disagreeing with the record it links to is worse than no badge.
     *
     * Measured before it existed: 14 queries for 12 rows, on a column that is not hidden by default.
     *
     * A correlated self-subquery, aliased — `whereColumn` against the outer `facility_work_orders`
     * needs the inner copy under a different name. Written with `addSelect([alias => query])` rather
     * than a raw `select *, (…)`, which SQLite accepts and MySQL rejects.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithPriorVisitCount(Builder $query, ?int $days = null): Builder
    {
        $days = $days ?? (int) config('facility.repeat_visit_days', 30);

        // Date arithmetic relative to EACH row has no portable spelling: SQLite wants
        // `datetime(col, '-30 days')`, MySQL wants `date_sub(col, interval 30 day)`. Branched here
        // in one place rather than discovered on the first real deploy — the suite runs SQLite and
        // would never have told us. `$days` is an int, so there is nothing to inject.
        $cutoff = $query->getConnection()->getDriverName() === 'sqlite'
            ? "datetime(facility_work_orders.created_at, '-{$days} days')"
            : "date_sub(facility_work_orders.created_at, interval {$days} day)";

        return $query->addSelect(['prior_visit_count' => static::query()
            ->from('facility_work_orders as prior')
            ->selectRaw('count(*)')
            ->whereColumn('prior.id', '!=', 'facility_work_orders.id')
            ->whereColumn('prior.trade_id', 'facility_work_orders.trade_id')
            ->where('prior.status', '!=', 'cancelled')
            ->whereNull('prior.parent_work_order_id')
            ->whereNull('prior.deleted_at')
            // The same "same THING" rule as the scope: the machine when there is one, else the
            // shop, and NOTHING when there is neither.
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $eq) => $eq
                    ->whereNotNull('facility_work_orders.equipment_id')
                    ->whereColumn('prior.equipment_id', 'facility_work_orders.equipment_id'))
                ->orWhere(fn (Builder $unit) => $unit
                    ->whereNull('facility_work_orders.equipment_id')
                    ->whereNotNull('facility_work_orders.unit_id')
                    ->whereNull('prior.equipment_id')
                    ->whereColumn('prior.unit_id', 'facility_work_orders.unit_id')))
            ->whereColumn('prior.created_at', '<', 'facility_work_orders.created_at')
            ->whereRaw("prior.created_at >= {$cutoff}"),
        ]);
    }

    /** How many times this same thing was already worked on inside the window. */
    public function priorVisitCount(?int $days = null): int
    {
        // A list that used `withPriorVisitCount()` already paid for this; re-querying per row is
        // the N+1 that scope exists to remove. Only honoured for the DEFAULT window, because a
        // caller asking for a different one is asking a different question.
        if ($days === null && $this->prior_visit_count !== null) {
            return (int) $this->prior_visit_count;
        }

        return $this->trade_id === null
            ? 0
            : static::query()->repeatsOf($this, $days)->count();
    }

    /**
     * Is this job a repeat — somebody has been here for the same thing already?
     *
     * A follow-up is never a repeat: see {@see scopeRepeatsOf}.
     */
    public function isRepeatVisit(?int $days = null): bool
    {
        return $this->parent_work_order_id === null && $this->priorVisitCount($days) > 0;
    }
}
