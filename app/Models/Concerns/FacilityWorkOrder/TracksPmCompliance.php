<?php

namespace App\Models\Concerns\FacilityWorkOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * **Was the planned work done when it was supposed to be?** (Maximo §6.)
 *
 * Extracted from `FacilityWorkOrder` on 2026-08-20, with three sibling concerns, because that model
 * had reached 1,149 lines carrying seven subjects that change for different reasons — a compliance
 * rule, a costing rule, a spend-control rule, an SLA rule. The size was the symptom; the cost was
 * that changing any one of them meant reading a file where all seven live.
 *
 * `scheduled_for` on a generated order IS the plan's due date — the generator copies it — so
 * compliance is exactly "completed on or before the day it was due". Derived, never stored:
 * `overdue` is a function of TODAY, and a stored one would need a sweep and go wrong on a day when
 * nothing happened.
 */
trait TracksPmCompliance
{
    /** Planned work, done on time. */
    public const PM_ON_TIME = 'on_time';

    /** Planned work, done — but after the date it was due. */
    public const PM_LATE = 'late';

    /** Planned work, not done, and the date has passed. The finding. */
    public const PM_OVERDUE = 'overdue';

    /** Planned work still in its window. Not yet anything. */
    public const PM_DUE = 'due';

    /**
     * **Was this preventive job done when it was supposed to be?** (Maximo §6.)
     *
     * `scheduled_for` on a generated order IS the plan's `next_due_date` — the generator copies it
     * — so compliance is exactly "completed on or before the day it was due". Both dates have been
     * stored since the module shipped; nothing ever compared them, so a preventive programme was a
     * list of intentions.
     *
     * **Measured strictly, with no tolerance window, and that is a stated deviation from Maximo.**
     * Maximo allows a PM tolerance, and a single global one would be wrong in both directions here:
     * three days is most of a weekly cleaning round and nothing at all on an annual overhaul. A
     * percentage of the cycle would be a policy nobody has agreed to. Strict never OVERSTATES
     * compliance — it is the safe direction — and the `late` rows are visible for an operator to
     * judge. Revisit with a per-plan tolerance if the operator asks, not before.
     *
     * Returns null where the question does not apply: a corrective job answers to its SLA instead,
     * and a cancelled one was never going to happen.
     */
    public function pmComplianceState(?CarbonImmutable $on = null): ?string
    {
        if ($this->work_order_type !== self::TYPE_PPM || $this->status === 'cancelled') {
            return null;
        }

        if ($this->scheduled_for === null) {
            return null;
        }

        $due = CarbonImmutable::parse($this->scheduled_for)->endOfDay();

        if ($this->completed_at !== null) {
            return CarbonImmutable::parse($this->completed_at)->lte($due) ? self::PM_ON_TIME : self::PM_LATE;
        }

        return ($on ?? CarbonImmutable::now())->gt($due) ? self::PM_OVERDUE : self::PM_DUE;
    }

    /**
     * The query twin of {@see pmComplianceState}'s `overdue` — planned work nobody has done.
     *
     * Shared by the filter and the plan's compliance count so they cannot drift about what
     * "overdue" means.
     */
    public function scopePmOverdue(Builder $query, ?CarbonImmutable $on = null): Builder
    {
        return $query
            ->where('work_order_type', self::TYPE_PPM)
            ->where('status', '!=', 'cancelled')
            ->whereNull('completed_at')
            ->whereNotNull('scheduled_for')
            ->whereDate('scheduled_for', '<', ($on ?? CarbonImmutable::now())->toDateString());
    }

    /** The query twin of `late`: done, but after the day it was due. */
    public function scopePmLate(Builder $query): Builder
    {
        return $query
            ->where('work_order_type', self::TYPE_PPM)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('completed_at')
            ->whereNotNull('scheduled_for')
            // date() on both sides: completing at 16:00 on the due date is ON TIME, and comparing
            // a datetime against a date column would call every afternoon completion late.
            ->whereRaw('date(completed_at) > date(scheduled_for)');
    }

    /** The query twin of `on_time`. */
    public function scopePmOnTime(Builder $query): Builder
    {
        return $query
            ->where('work_order_type', self::TYPE_PPM)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('completed_at')
            ->whereNotNull('scheduled_for')
            ->whereRaw('date(completed_at) <= date(scheduled_for)');
    }
}
