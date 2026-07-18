<?php

namespace App\Services;

use App\Models\MaintenancePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /** @return int number of work orders raised */
    public function run(?string $onOrBefore = null): int
    {
        $due = $onOrBefore ?? now()->toDateString();
        $created = 0;
        $this->failures = [];

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
            }
        });

        return $created;
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
                $plan->save();

                return;
            }

            $order = $plan->workOrders()->create([
                'asset_id' => $plan->asset_id,
                'unit_id' => $plan->unit_id,
                // Carried onto the order so the job records which machine it was against,
                // and keeps saying so after the plan is edited or deleted (FR-PPM-03).
                'equipment_id' => $plan->equipment_id,
                'title' => $plan->title,
                'category' => $plan->category,
                'status' => 'open',
                'scheduled_for' => $scheduledFor,
                'department_id' => $plan->department_id,
                'vendor_id' => $plan->vendor_id,
                'notes' => $plan->description,
            ]);

            foreach ((array) $plan->checklist as $label) {
                if (trim((string) $label) !== '') {
                    $order->items()->create(['label' => $label]);
                }
            }

            $plan->advanceDue();
            $plan->save();
            $created++;
        });
    }
}
