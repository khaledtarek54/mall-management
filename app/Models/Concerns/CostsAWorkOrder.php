<?php

namespace App\Models\Concerns;

use App\Models\Expense;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderLabour;
use App\Models\FacilityWorkOrderPart;
use App\Models\VendorBill;

/**
 * **A COST CHANNEL OF A WORK ORDER** — the one place all four of them wire their recompute.
 *
 * `FacilityWorkOrder::recomputeCosts()` is the single source of truth for what a job cost, written
 * the way `Invoice::recomputeTotals()` is: several independent channels change the number, exactly
 * one method computes it, and every channel calls it. There are FOUR channels —
 * {@see FacilityWorkOrderLabour}, {@see FacilityWorkOrderPart},
 * {@see VendorBill} and {@see Expense} — and using this trait is how a
 * model says it is one. A fifth is wired by USING it rather than by its author remembering a shape.
 *
 * ## The half that was written three times and forgotten once (SW-085)
 *
 * A document MOVED between jobs leaves the job it LEFT overstated, so the previous owner has to
 * recompute too. Measured before this trait existed:
 * `grep -rn "getOriginal('facility_work_order_id')" app/` matched exactly three files —
 * `VendorBill`, `Expense` and `FacilityWorkOrderPart` — each carrying a byte-identical twelve-line
 * `saved` block doing it, while `FacilityWorkOrderLabour`, the fourth channel, carried
 * `static::saved(fn ($line) => $line->workOrder?->recomputeCosts())` and nothing else. Three copies
 * of one rule is three chances to write it and one chance to miss it, which is what happened.
 *
 * **Not reachable from a screen today, and that is not a reason to leave it.**
 * `WorkOrderLabourRelationManager` is a child of the job and stamps the parent, so no form re-homes
 * a timesheet line; `ExpenseForm.php:56` and `VendorBillForm.php:115` DO offer a work-order picker,
 * which is why those two were guarded in the first place. A console fix-up, an importer or the next
 * screen is the operator this closes it for.
 *
 * ## `workOrderForCosting()`, never a display relation
 *
 * A fresh `find()` on the CURRENT foreign key, named apart from any display relation so the costing
 * hook cannot be broken by someone renaming or re-scoping a relation that exists for something
 * else. It also cannot be answered from a STALE relation cache, which is the other half of the same
 * fault: labour read `$line->workOrder`, a `belongsTo` whose loaded value is whatever the foreign
 * key pointed at when it was first touched — so a row loaded with that relation warm and then
 * re-homed would have recomputed the OLD job and left the NEW one understated.
 *
 * **This posts NOTHING.** The money is already in the ledger through `StockMovement`,
 * `VendorBill`/`Expense` and `Payroll`; the `act_*` columns are a management dimension over posted
 * money, and a journalizer here would post every maintenance cost twice AND BALANCED.
 * `WorkOrderIsACostObjectNotAGlSourceTest` fails the build on one.
 */
trait CostsAWorkOrder
{
    /** Laravel calls this automatically for a trait named like the method. */
    public static function bootCostsAWorkOrder(): void
    {
        // The work order is the cost object and `recomputeCosts()` is its single source of truth,
        // so every channel that changes what a job cost calls it — the same discipline that makes
        // every AR settlement channel call `Invoice::recomputeTotals()`. Missing one here would
        // leave a job quietly understating its cost, which is the failure nobody notices.
        static::saved(function (self $m): void {
            $m->workOrderForCosting()?->recomputeCosts();

            // A document MOVED between jobs leaves the old one overstated, so the previous owner
            // recomputes too. `getOriginal()` still holds it inside `saved`.
            $was = $m->getOriginal('facility_work_order_id');
            if ($was !== null && (int) $was !== (int) $m->facility_work_order_id) {
                FacilityWorkOrder::find($was)?->recomputeCosts();
            }
        });

        static::deleted(fn (self $m) => $m->workOrderForCosting()?->recomputeCosts());
        static::restored(fn (self $m) => $m->workOrderForCosting()?->recomputeCosts());
    }

    /** The job this cost belongs to, for {@see FacilityWorkOrder::recomputeCosts()}. */
    public function workOrderForCosting(): ?FacilityWorkOrder
    {
        return $this->facility_work_order_id === null
            ? null
            : FacilityWorkOrder::find($this->facility_work_order_id);
    }
}
