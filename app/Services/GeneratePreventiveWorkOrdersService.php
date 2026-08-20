<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderLabour;
use App\Models\ServicePlan;
use App\Models\Vendor;
use App\Notifications\PreventiveGenerationFailedNotification;
use App\Notifications\WorkOrderRaisedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Raises preventive-maintenance work orders for every plan that's due (module 26).
 * Idempotent + lock-safe: each plan row is locked and re-checked inside its own
 * transaction, and next_due_date advances by the plan's frequency after a work order
 * is raised — so an overlapping scan (or a re-run) can't double-generate. A long-dormant
 * plan catches up one cycle per run.
 *
 * Failures are contained per plan: one bad row must never stop every other property's
 * maintenance from being raised. That matters more since `advanceDue()` now throws on an
 * unknown frequency unit instead of silently treating it as months — the containment and
 * the throw were added together, and neither is safe without the other.
 */
class GeneratePreventiveWorkOrdersService
{
    /** @var array<int,string> plan id => failure reason, for the caller to surface */
    public array $failures = [];

    /** @var array<int,int> ids of the work orders raised this run (for the post-commit notify). */
    private array $raisedOrderIds = [];

    /** @return int number of work orders raised */
    /**
     * Generate for ONE plan, now — the manual counterpart of the nightly sweep.
     *
     * The scheduled run existed and there was no way to trigger it from the panel. A plan could sit
     * OVERDUE, or `generation_failing` with its error on screen, and the only remedies were waiting
     * for cron or opening a shell — on a screen that already told the operator something was wrong
     * (2026-08-18). CAM's pool and a lease's billing both offer the same act as a button; this
     * module's producer did not.
     *
     * Routes through the same private path the sweep uses — the trigger type decides which — so a
     * manual generation cannot take a different route from the automatic one and produce a
     * different work order.
     */
    public function runFor(ServicePlan $plan, ?string $onOrBefore = null): int
    {
        $due = $onOrBefore ?? now()->toDateString();
        $this->failures = [];
        $this->raisedOrderIds = [];

        $created = $this->attempt($plan->getKey(), fn (): int => $plan->isUsageTriggered()
            ? $this->generateForUsage($plan->getKey(), $due)
            : $this->generateFor($plan->getKey(), $due));

        $this->notifyRaised();

        return $created;
    }

    public function run(?string $onOrBefore = null): int
    {
        $due = $onOrBefore ?? now()->toDateString();
        $created = 0;
        $this->failures = [];
        $this->raisedOrderIds = [];

        // TIME plans — the calendar round. `due()` filters on `trigger_type` so a usage plan,
        // which carries a NOT-NULL `next_due_date` like every other row, cannot also match here.
        ServicePlan::due($due)->select('id')->get()->each(function ($row) use ($due, &$created) {
            // Per-plan containment, mirroring ScanTenantRequestSlaBreachesCommand's per-row
            // catch. Without it one corrupt plan aborts the nightly run and every property
            // silently stops getting work orders.
            $created += $this->attempt((int) $row->id, fn (): int => $this->generateFor((int) $row->id, $due));
        });

        // USAGE plans — the counter round. Evaluated in PHP rather than filtered in SQL because
        // due-ness compares the meter's latest reading against the plan's baseline, and putting
        // that comparison in a join here would be a second copy of the rule that
        // `ServicePlan::isDueByUsage()` already owns.
        ServicePlan::usageTriggered()->select('id')->get()->each(function ($row) use ($due, &$created) {
            $created += $this->attempt((int) $row->id, fn (): int => $this->generateForUsage((int) $row->id, $due));
        });

        // FRD MNT-2 — a scheduled service must NOT be raised silently. Notify AFTER the
        // per-plan transactions commit (only committed orders), so a rolled-back generation
        // never sends a bell. Throwable-guarded per the module's convention: a notification
        // hiccup must never make the nightly run report a failure it didn't have.
        $this->notifyRaised();

        return $created;
    }

    /**
     * Per-plan failure containment, shared by both trigger rounds.
     *
     * One corrupt plan must never stop every other property's maintenance from being raised —
     * the reason this exists at all, and the reason it is a helper now rather than a copied
     * try/catch: a second round with its own catch is a second place for the containment to be
     * subtly different.
     *
     * Returns the number of orders the attempt raised — 0 on failure. It RETURNS rather than
     * incrementing a by-reference counter because the call sites pass `fn () =>`, and an arrow
     * function captures by VALUE: the count incremented inside a copy and `run()` reported 0 orders
     * raised on a run that raised plenty. Caught by the time-plan control test, which is what a
     * control is for.
     *
     * @param  callable(): int  $work
     */
    private function attempt(int $planId, callable $work): int
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            $this->failures[$planId] = $e->getMessage();

            Log::warning('Preventive generation failed for a plan', [
                'service_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);

            // Outside the rolled-back transaction, deliberately: the stamp is the only surviving
            // trace of an attempt that undid everything else it did.
            $this->recordFailure($planId, $e->getMessage());

            return 0;
        }
    }

    /**
     * Stamp the plan that could not generate, and bell the property once when it first gets stuck.
     *
     * The containment above and the all-or-nothing transaction below are each right on their own,
     * and together they mean a failing plan retries the same cycle every night forever. Until now
     * the only trace was a `Log::warning` and a non-zero exit from a cron job with no `onFailure`
     * hook — so the statutory round stopped happening and nobody found out.
     *
     * The plan keeps its `next_due_date`: a missed inspection is a backlog item, not something to
     * skip past. What changes is that the backlog is now visible.
     *
     * Alerts on the transition into failure only, for the same reason the ledger-drift alert does —
     * a nightly repeat of a known problem is a message people filter. Best-effort throughout: this
     * is the error path, and it must not become the thing that breaks the run.
     */
    private function recordFailure(int $planId, string $reason): void
    {
        try {
            /** @var ServicePlan|null $plan */
            $plan = ServicePlan::find($planId);
            if ($plan === null) {
                return;
            }

            $wasFailing = $plan->generationIsFailing();

            $plan->forceFill([
                'last_generation_failed_at' => now(),
                'last_generation_error' => Str::limit($reason, 480),
            ])->saveQuietly();

            if ($wasFailing) {
                return;
            }

            $recipients = app(AssetStaffRecipients::class)->for($plan->asset_id, ['manager', 'operations']);
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new PreventiveGenerationFailedNotification($plan, $reason));
            }
        } catch (\Throwable $e) {
            Log::warning('Preventive generation failure could not be recorded', [
                'service_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Bell the property's operations staff for each work order this run actually raised. */
    private function notifyRaised(): void
    {
        try {
            if ($this->raisedOrderIds === []) {
                return;
            }

            $staff = app(AssetStaffRecipients::class);
            FacilityWorkOrder::whereKey($this->raisedOrderIds)->get()->each(function (FacilityWorkOrder $order) use ($staff) {
                $recipients = $staff->for($order->asset_id, ['manager', 'operations']);
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new WorkOrderRaisedNotification($order));
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Preventive generation raised-notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function generateFor(int $planId, string $due): int
    {
        return (int) DB::transaction(function () use ($planId, $due): int {
            /** @var ServicePlan|null $plan */
            $plan = ServicePlan::whereKey($planId)->lockForUpdate()->first();

            if (! $plan || ! $plan->is_active) {
                return 0;
            }

            // Re-check due under the lock (string compare is correct for ISO dates).
            $scheduledFor = $plan->next_due_date->toDateString();

            if ($scheduledFor > $due) {
                return 0;
            }

            // Idempotency backstop: never raise two orders for the same plan cycle.
            if ($plan->workOrders()->whereDate('scheduled_for', $scheduledFor)->exists()) {
                $plan->advanceDue();
                $plan->forceFill(['last_generation_failed_at' => null, 'last_generation_error' => null]);
                $plan->save();

                return 0;
            }

            $this->raiseOrder($plan, $scheduledFor);

            $plan->advanceDue();
            // A plan that generates is no longer stuck. Cleared inside the same transaction as the
            // order it raised, so the stamp can never outlive the failure it describes.
            $plan->forceFill(['last_generation_failed_at' => null, 'last_generation_error' => null]);
            $plan->save();

            return 1;
        });
    }

    /**
     * Raise a job for a plan whose COUNTER has passed its threshold.
     *
     * Same lock-and-re-check discipline as the time round, and for the same reason: the due-ness
     * test is re-run under the row lock, so an overlapping scan (or a re-run of the command) cannot
     * raise two jobs for one service.
     *
     * **What advances is the baseline, not a date.** `usage_at_last_generation` moves to the reading
     * the job was raised at — so the next job needs another full threshold of movement, and a meter
     * read twice on the same day does not raise two.
     */
    private function generateForUsage(int $planId, string $due): int
    {
        return (int) DB::transaction(function () use ($planId, $due): int {
            /** @var ServicePlan|null $plan */
            $plan = ServicePlan::whereKey($planId)->lockForUpdate()->first();

            if (! $plan) {
                return 0;
            }

            // Re-checked under the lock. `isDueByUsage()` re-reads the meter, so this is the real
            // guard and the scope above is only a cheap pre-filter.
            if (! $plan->isDueByUsage()) {
                return 0;
            }

            $reading = $plan->latestUsageReading();

            // The job is dated the day the scan runs, not a schedule date the plan does not have.
            // Idempotency backstop mirroring the time round: never two orders for one plan on one
            // day, whatever the counter says.
            if ($plan->workOrders()->whereDate('scheduled_for', $due)->exists()) {
                $plan->forceFill([
                    'usage_at_last_generation' => $reading,
                    'last_generation_failed_at' => null,
                    'last_generation_error' => null,
                ])->save();

                return 0;
            }

            $this->raiseOrder($plan, $due);

            // The baseline moves to the reading that triggered this job — NOT to
            // `baseline + threshold`. A machine that ran 700 hours between reads has had 700 hours
            // of wear, and crediting it with only 500 would raise a second, immediately-due job for
            // a service that has just been scheduled.
            $plan->forceFill([
                'usage_at_last_generation' => $reading,
                'last_generation_failed_at' => null,
                'last_generation_error' => null,
            ])->save();

            return 1;
        });
    }

    /**
     * Create the work order + its checklist for a plan. Shared by both triggers, so a job raised on
     * running hours is the same job in every respect except what made it due.
     */
    private function raiseOrder(ServicePlan $plan, string $scheduledFor): FacilityWorkOrder
    {
        // A contractor who cannot be dispatched must not stop the round HAPPENING.
        //
        // `FacilityWorkOrder::saving()` refuses a non-dispatchable vendor, which is right —
        // an uninsured contractor on the mall floor is the operator's liability. But that throw
        // rolled back the whole cycle, so a lapsed COI silently cancelled the plan's statutory
        // inspection rather than merely its assignment. The compliance gate governs who is sent,
        // not whether the work exists: raise it unassigned, say why on the order, and let a
        // coordinator pick somebody compliant. This is what the FM specialists do.
        $vendorId = $plan->vendor_id;
        $complianceNote = null;
        if ($vendorId !== null && ! Vendor::query()->whereKey($vendorId)->assignable()->exists()) {
            $vendorId = null;
            $complianceNote = __('admin.service_plans.vendor_not_dispatchable', [
                'vendor' => (string) Vendor::withTrashed()->whereKey($plan->vendor_id)->value('name'),
            ]);
        }

        // What made this job due, on the job itself. A technician holding a work order for a
        // machine serviced on running hours needs to know it was the counter and not the calendar —
        // and after the plan is edited or deleted, the order is the only thing that still says so.
        $usageNote = null;
        if ($plan->isUsageTriggered()) {
            $usageNote = __('admin.service_plans.raised_by_usage', [
                'usage' => rtrim(rtrim(number_format((float) ($plan->usageSinceLastGeneration() ?? 0), 2), '0'), '.'),
                'uom' => (string) ($plan->utilityMeter?->unit_of_measurement ?? ''),
                'meter' => (string) ($plan->utilityMeter?->meter_number ?? ''),
            ]);
        }

        /** @var FacilityWorkOrder $order */
        $order = $plan->workOrders()->create([
            'asset_id' => $plan->asset_id,
            'unit_id' => $plan->unit_id,
            // The location a soft-service round is performed on (cleaning, landscaping). Carried
            // onto the order so it still says where after the plan is edited or deleted.
            'area_id' => $plan->area_id,
            // Carried onto the order so the job records which machine it was against,
            // and keeps saying so after the plan is edited or deleted (FR-PPM-03).
            'equipment_id' => $plan->equipment_id,
            'title' => $plan->title,
            'trade_id' => $plan->trade_id,
            'status' => 'open',
            // A routine round on a critical machine is still a critical machine. Without this
            // the generator fell to the column default and every plan produced `medium`,
            // whatever it was servicing.
            'priority' => ($eq = $plan->getRelationValue('equipment')) instanceof Equipment
                ? $eq->defaultWorkOrderPriority()
                : 'medium',
            'scheduled_for' => $scheduledFor,
            // **The plan's estimate, priced on the day the job is raised** (Maximo §3). Hours live
            // on the plan and become money at the trade's rate NOW — storing a labour cost on the
            // plan would freeze a rate for its whole life, which is exactly what `charges.vat_rate`
            // did wrong before 2026-08-12. Without this the whole preventive programme is
            // un-estimated for ever and `costVariance()` is null on every job it raises.
            'est_labour_hours' => $plan->est_labour_hours,
            'est_labour_cost' => $plan->est_labour_hours === null
                ? null
                : round((float) $plan->est_labour_hours * (float) (FacilityWorkOrderLabour::rateFor($plan->trade_id) ?? 0), 2),
            'est_material_cost' => $plan->est_material_cost,
            'est_service_cost' => $plan->est_service_cost,
            'department_id' => $plan->department_id,
            'vendor_id' => $vendorId,
            'notes' => trim(implode("\n\n", array_filter([
                (string) $plan->description,
                $usageNote,
                $complianceNote,
            ]))) ?: null,
        ]);

        foreach ((array) $plan->checklist as $label) {
            if (trim((string) $label) !== '') {
                $order->items()->create(['label' => $label]);
            }
        }

        // **A ROUTE becomes one line per machine** (Maximo §6). One work order with a line per
        // stop, not a work order per stop: per-stop children earn their keep when each stop needs
        // separate assignment or costing, and 42 work orders for one walk is the failure the route
        // exists to prevent.
        //
        // The line carries `equipment_id`, which is the whole point — "Extinguisher 2-17 — fail"
        // stops being a string and becomes a fact about a device, so the round can report which
        // ones failed and 2-17's own history is no longer empty.
        foreach ($plan->stops()->with('equipment')->get() as $stop) {
            $order->items()->create([
                'equipment_id' => $stop->equipment_id,
                // `Equipment::label()` is already "CODE — Name"; prefixing the code again gave
                // "AHU-01 AHU-01 — Air handling unit" on the engineer's sheet.
                'label' => ($stop->equipment?->label() ?? __('admin.facility.stop_missing_machine'))
                    .($stop->note ? ' — '.$stop->note : ''),
            ]);
        }

        $this->raisedOrderIds[] = $order->id;

        return $order;
    }
}
