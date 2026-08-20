<?php

namespace App\Models\Concerns\FacilityWorkOrder;

use App\Models\Trade;
use App\Models\WorkOrderProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The before-the-money control: what a contractor may spend, and what they quoted.
 *
 * ServiceChannel §3. Over-NTE is SHOWN and never blocked — the same settled reasoning as the
 * three-way match, because a job can legitimately grow for something nobody could have proposed
 * for. The control is that a contractor should have proposed BEFORE exceeding.
 *
 * Extracted from `FacilityWorkOrder` on 2026-08-20 — see {@see TracksPmCompliance} for the reason.
 */
trait ControlsSpendAgainstNte
{
    /** Laravel calls this automatically for a trait named like the method. */
    public static function bootControlsSpendAgainstNte(): void
    {

        // The trade's default ceiling, applied when a job is raised and not afterwards: changing
        // a trade's default must not silently re-authorise every open job in it. An explicit
        // amount on the form always wins, and a trade with no default leaves the job with no NTE —
        // honest, where 0 would mean "may spend nothing".
        static::creating(function (self $order) {
            if ($order->nte_amount === null && $order->trade_id !== null) {
                $order->nte_amount = Trade::query()->whereKey($order->trade_id)->value('default_nte');
            }
        });
    }

    /** Quotes raised against this job. {@see WorkOrderProposal} */
    public function proposals(): HasMany
    {
        return $this->hasMany(WorkOrderProposal::class);
    }

    /**
     * **Has this job cost more than the contractor was authorised to spend?**
     *
     * The amount by which actual cost exceeds the not-to-exceed figure, or null when there is no
     * NTE (nobody set a ceiling, so nothing was exceeded) or the job is inside it.
     *
     * **Shown, never blocked** — the same settled reasoning as `PurchaseRequest::billingVariance()`:
     * a job can legitimately grow for something nobody could have proposed for, so jamming accounts
     * payable would be wrong. The control is that a contractor should have submitted a proposal
     * BEFORE exceeding; the enforcement is that the breach is visible and attributable. A stated
     * deviation from ServiceChannel, which does hold the invoice.
     */
    public function overNteBy(): ?float
    {
        if ($this->nte_amount === null) {
            return null;
        }

        $over = round((float) $this->act_total_cost - (float) $this->nte_amount, 2);

        return $over > 0 ? $over : null;
    }

    /** The query twin of {@see overNteBy}, for the filter and any report. */
    public function scopeOverNte(Builder $query): Builder
    {
        return $query
            ->whereNotNull('nte_amount')
            ->whereColumn('act_total_cost', '>', 'nte_amount');
    }
}
