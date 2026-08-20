<?php

namespace App\Models\Concerns\FacilityWorkOrder;

use App\Models\Expense;
use App\Models\FacilityWorkOrderLabour;
use App\Models\FacilityWorkOrderPart;
use App\Models\VendorBill;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * **The work order as a COST OBJECT** (Maximo §4) — what this job cost, in three buckets.
 *
 * `recomputeCosts()` is the single source of truth, written the way `Invoice::recomputeTotals()` is:
 * several independent channels change the number, so exactly one method computes it and every
 * channel calls it. Never set an `act_*` column anywhere else.
 *
 * **This posts NOTHING.** The money is already in the ledger through `StockMovement`,
 * `VendorBill`/`Expense` and `Payroll`; these columns are a management dimension over posted money,
 * and a journalizer here would post every maintenance cost twice AND BALANCED.
 * `WorkOrderIsACostObjectNotAGlSourceTest` fails the build on it.
 *
 * Extracted from `FacilityWorkOrder` on 2026-08-20 — see {@see TracksPmCompliance} for the reason.
 */
trait HasWorkOrderCost
{
    /** Laravel calls this automatically for a trait named like the method. */
    public static function bootHasWorkOrderCost(): void
    {
        // The planned total is a function of its three parts, so it is derived on EVERY save.
        // `recomputeCosts()` is called by the COST channels — labour, parts, bills — and none of
        // them touches an estimate, so without this an operator editing `est_service_cost` left
        // the stored total at its previous value and `costVariance()` reported against a stale
        // figure. `saveQuietly()` does not fire this, which is exactly right: the recompute path
        // calls the derivation directly and cannot loop.
        static::saving(fn (self $order) => $order->deriveEstimatedTotal());
    }

    /** Hours reported against this job. {@see FacilityWorkOrderLabour} */
    public function labour(): HasMany
    {
        return $this->hasMany(FacilityWorkOrderLabour::class);
    }

    /** Contractor invoices raised against this job — the service bucket. */
    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    /** Direct/petty-cash costs booked to this job — the service bucket's other road. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * **The single source of truth for what this job cost.**
     *
     * Written the way `Invoice::recomputeTotals()` is, and for the same reason: several independent
     * channels change the number, so exactly one method may compute it and every channel calls it.
     * Never set an `act_*` column anywhere else.
     *
     * THREE CHANNELS, and adding a fourth means adding it here AND wiring its model events:
     *
     *   labour   — `facility_work_order_labour`, hours x the craft rate frozen at entry
     *   material — approved/recorded part draws (`facility_work_order_parts.value`)
     *   service  — vendor bills + expenses booked to this job
     *
     * **NET of tax, and net of any SLA penalty applied to the bill.** VAT is recoverable and is not
     * a cost of the job; a penalty credited against a contractor's invoice genuinely reduces what
     * the work cost us, and `SlaPenaltyJournalizer` already credits the same expense account, so
     * taking it off here keeps this figure and the ledger telling the same story.
     *
     * **A cancelled document costs nothing** — excluded, exactly as `VendorBill::recompute()`
     * excludes a voided payment.
     *
     * This posts NOTHING. See the migration docblock: the money is already in the ledger through
     * three other documents, and these columns are a management dimension over it.
     */
    public function recomputeCosts(): void
    {
        $labour = $this->labour()
            ->selectRaw('coalesce(sum(hours), 0) as h, coalesce(sum(cost), 0) as c')
            ->first();

        $this->act_labour_hours = round((float) ($labour->h ?? 0), 2);
        $this->act_labour_cost = round((float) ($labour->c ?? 0), 2);

        // Only a part that actually left the store (or was recorded as bought for the job) is a
        // cost. A `pending` request is a proposal and a `rejected` one never happened.
        $this->act_material_cost = round((float) $this->parts()
            ->whereIn('status', [FacilityWorkOrderPart::STATUS_APPROVED, FacilityWorkOrderPart::STATUS_RECORDED])
            ->sum('value'), 2);

        $bills = round((float) $this->vendorBills()
            ->where('status', '!=', 'cancelled')
            ->selectRaw('coalesce(sum(subtotal - coalesce(penalty_applied_amount, 0)), 0) as net')
            ->value('net'), 2);

        $expenses = round((float) $this->expenses()
            ->where('status', '!=', 'cancelled')
            ->sum('amount'), 2);

        $this->act_service_cost = round($bills + $expenses, 2);

        $this->act_total_cost = round(
            (float) $this->act_labour_cost + (float) $this->act_material_cost + (float) $this->act_service_cost,
            2
        );

        $this->deriveEstimatedTotal();

        // saveQuietly: a derivation, not an operator action. Logging it would bury the change
        // somebody actually made under a cost row nobody typed.
        $this->saveQuietly();
    }

    /**
     * The planned total, from its parts.
     *
     * Derived for the same reason the actual one is: an operator who estimated two of three buckets
     * should not also have to add them up — and a stored total nothing re-derives is a second truth
     * about the same money.
     *
     * **Called from `saving` as well as from `recomputeCosts()`, and that is the whole point.** The
     * cost channels are what call `recomputeCosts()`, and none of them touches an estimate — so
     * editing `est_service_cost` on the form left `est_total_cost` at whatever it had been, and
     * `costVariance()` (the number an operator acts on) was computed from the stale figure.
     * Measured on the live database, not theorised.
     */
    private function deriveEstimatedTotal(): void
    {
        $stated = array_filter(
            [$this->est_labour_cost, $this->est_material_cost, $this->est_service_cost],
            fn ($v) => $v !== null,
        );

        $this->est_total_cost = $stated === []
            ? null                                   // nobody estimated anything; NOT zero
            : round(array_sum(array_map('floatval', $stated)), 2);
    }

    /**
     * Planned minus actual on the total, or null when nothing was planned.
     *
     * The number an operator can act on: a job estimated at 4 hours that consumed 14 is the
     * finding, and one showing only "14" is a figure nobody can do anything with.
     */
    public function costVariance(): ?float
    {
        return $this->est_total_cost === null
            ? null
            : round((float) $this->est_total_cost - (float) $this->act_total_cost, 2);
    }
}
