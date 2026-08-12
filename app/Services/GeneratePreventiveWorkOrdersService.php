<?php

namespace App\Services;

use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Models\Vendor;
use App\Notifications\PreventiveGenerationFailedNotification;
use App\Notifications\WorkOrderRaisedNotification;
use App\Services\AssetStaffRecipients;
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
    public function run(?string $onOrBefore = null): int
    {
        $due = $onOrBefore ?? now()->toDateString();
        $created = 0;
        $this->failures = [];
        $this->raisedOrderIds = [];

        MaintenancePlan::due($due)->select('id')->get()->each(function ($row) use ($due, &$created) {
            // Per-plan containment, mirroring ScanTenantRequestSlaBreachesCommand's per-row
            // catch. Without it one corrupt plan aborts the nightly run and every property
            // silently stops getting work orders.
            try {
                $this->generateFor((int) $row->id, $due, $created);
            } catch (\Throwable $e) {
                $this->failures[(int) $row->id] = $e->getMessage();

                Log::warning('Preventive generation failed for a plan', [
                    'maintenance_plan_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);

                // Outside the rolled-back transaction, deliberately: the stamp is the only surviving
                // trace of an attempt that undid everything else it did.
                $this->recordFailure((int) $row->id, $e->getMessage());
            }
        });

        // FRD MNT-2 — a scheduled service must NOT be raised silently. Notify AFTER the
        // per-plan transactions commit (only committed orders), so a rolled-back generation
        // never sends a bell. Throwable-guarded per the module's convention: a notification
        // hiccup must never make the nightly run report a failure it didn't have.
        $this->notifyRaised();

        return $created;
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
            /** @var MaintenancePlan|null $plan */
            $plan = MaintenancePlan::find($planId);
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
                'maintenance_plan_id' => $planId,
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
            MaintenanceWorkOrder::whereKey($this->raisedOrderIds)->get()->each(function (MaintenanceWorkOrder $order) use ($staff) {
                $recipients = $staff->for($order->asset_id, ['manager', 'operations']);
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new WorkOrderRaisedNotification($order));
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Preventive generation raised-notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function generateFor(int $planId, string $due, int &$created): void
    {
        DB::transaction(function () use ($planId, $due, &$created) {
            /** @var MaintenancePlan|null $plan */
            $plan = MaintenancePlan::whereKey($planId)->lockForUpdate()->first();

            if (! $plan || ! $plan->is_active) {
                return;
            }

            // Re-check due under the lock (string compare is correct for ISO dates).
            $scheduledFor = $plan->next_due_date->toDateString();

            if ($scheduledFor > $due) {
                return;
            }

            // Idempotency backstop: never raise two orders for the same plan cycle.
            if ($plan->workOrders()->whereDate('scheduled_for', $scheduledFor)->exists()) {
                $plan->advanceDue();
                $plan->forceFill(['last_generation_failed_at' => null, 'last_generation_error' => null]);
                $plan->save();

                return;
            }

            // A contractor who cannot be dispatched must not stop the round HAPPENING.
            //
            // `MaintenanceWorkOrder::saving()` refuses a non-dispatchable vendor, which is right —
            // an uninsured contractor on the mall floor is the operator's liability. But that throw
            // rolled back the whole cycle, so a lapsed COI silently cancelled the plan's statutory
            // inspection rather than merely its assignment. The compliance gate governs who is sent,
            // not whether the work exists: raise it unassigned, say why on the order, and let a
            // coordinator pick somebody compliant. This is what the FM specialists do.
            $vendorId = $plan->vendor_id;
            $complianceNote = null;
            if ($vendorId !== null && ! Vendor::query()->whereKey($vendorId)->assignable()->exists()) {
                $vendorId = null;
                $complianceNote = __('admin.maintenance_plans.vendor_not_dispatchable', [
                    'vendor' => (string) Vendor::withTrashed()->whereKey($plan->vendor_id)->value('name'),
                ]);
            }

            /** @var MaintenanceWorkOrder $order */
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
                'category' => $plan->category,
                'status' => 'open',
                // A routine round on a critical machine is still a critical machine. Without this
                // the generator fell to the column default and every plan produced `medium`,
                // whatever it was servicing.
                'priority' => ($eq = $plan->getRelationValue('equipment')) instanceof \App\Models\Equipment
                    ? $eq->defaultWorkOrderPriority()
                    : 'medium',
                'scheduled_for' => $scheduledFor,
                'department_id' => $plan->department_id,
                'vendor_id' => $vendorId,
                'notes' => trim(implode("\n\n", array_filter([(string) $plan->description, $complianceNote]))) ?: null,
            ]);

            foreach ((array) $plan->checklist as $label) {
                if (trim((string) $label) !== '') {
                    $order->items()->create(['label' => $label]);
                }
            }

            $plan->advanceDue();
            // A plan that generates is no longer stuck. Cleared inside the same transaction as the
            // order it raised, so the stamp can never outlive the failure it describes.
            $plan->forceFill(['last_generation_failed_at' => null, 'last_generation_error' => null]);
            $plan->save();
            $created++;
            $this->raisedOrderIds[] = $order->id;
        });
    }
}
