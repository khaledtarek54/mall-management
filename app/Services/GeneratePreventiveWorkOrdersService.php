<?php

namespace App\Services;

use App\Models\MaintenancePlan;
use Illuminate\Support\Facades\DB;

/**
 * Raises preventive-maintenance work orders for every plan that's due (module 26).
 * Idempotent + lock-safe: each plan row is locked and re-checked inside its own
 * transaction, and next_due_date advances by the plan's frequency after a work order
 * is raised — so an overlapping scan (or a re-run) can't double-generate. A long-dormant
 * plan catches up one cycle per run.
 */
class GeneratePreventiveWorkOrdersService
{
    /** @return int number of work orders raised */
    public function run(?string $onOrBefore = null): int
    {
        $due = $onOrBefore ?? now()->toDateString();
        $created = 0;

        MaintenancePlan::due($due)->select('id')->get()->each(function ($row) use ($due, &$created) {
            DB::transaction(function () use ($row, $due, &$created) {
                /** @var MaintenancePlan|null $plan */
                $plan = MaintenancePlan::whereKey($row->id)->lockForUpdate()->first();
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
        });

        return $created;
    }
}
