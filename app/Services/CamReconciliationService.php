<?php

namespace App\Services;

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\Lease;
use Illuminate\Support\Facades\DB;

class CamReconciliationService
{
    /**
     * Generate one CamAllocation per active lease in the pool's asset,
     * with each lease's pro-rata share computed from leased sqm.
     *
     * Allocated amount = (lease unit sqm / total leased sqm) * total_actual_expense.
     * Estimated paid = (lease unit sqm / total leased sqm) * total_estimated_collected.
     * True-up = allocated - estimated. Positive means under-collected (tenant owes more).
     *
     * Idempotent: existing allocations are updated, not duplicated.
     */
    public function generateAllocations(CamExpensePool $pool): int
    {
        $leases = Lease::query()
            ->whereHas('unit', fn ($q) => $q->where('asset_id', $pool->asset_id))
            ->where('status', 'active')
            ->with('unit')
            ->get();

        $totalSqm = (float) $leases->sum(fn (Lease $l) => (float) ($l->unit?->area_sqm ?? 0));

        if ($totalSqm <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($pool, $leases, $totalSqm) {
            $count = 0;

            foreach ($leases as $lease) {
                $sqm = (float) ($lease->unit?->area_sqm ?? 0);
                if ($sqm <= 0) {
                    continue;
                }

                $share = $sqm / $totalSqm;
                $allocated = round((float) $pool->total_actual_expense * $share, 2);
                $estimated = round((float) $pool->total_estimated_collected * $share, 2);
                $trueUp = round($allocated - $estimated, 2);

                CamAllocation::updateOrCreate(
                    [
                        'cam_expense_pool_id' => $pool->id,
                        'lease_id' => $lease->id,
                    ],
                    [
                        'pro_rata_share_pct' => round($share * 100, 4),
                        'allocated_amount' => $allocated,
                        'estimated_paid' => $estimated,
                        'true_up_amount' => $trueUp,
                        'status' => 'pending',
                    ],
                );
                $count++;
            }

            return $count;
        });
    }

    /**
     * Bill a single allocation: creates a one-off Charge on the lease for the
     * true_up_amount. Positive true-up = tenant owes more. Negative = credit due
     * (we still create the charge with negative amount so the next invoice nets it).
     *
     * Idempotent: re-billing an already-billed allocation is a no-op.
     */
    public function bill(CamAllocation $allocation): CamAllocation
    {
        if ($allocation->status === 'billed') {
            return $allocation;
        }

        return DB::transaction(function () use ($allocation) {
            $pool = $allocation->pool;
            $year = $pool->period_year;
            $amount = (float) $allocation->true_up_amount;

            $charge = Charge::create([
                'lease_id' => $allocation->lease_id,
                'name' => "CAM Reconciliation — {$year}",
                'type' => 'other',
                'amount' => $amount,
                'currency' => 'EGP',
                'frequency' => 'one_time',
                'vat_applicable' => false,
                'vat_rate' => 0,
                'start_date' => "{$year}-01-01",
                'end_date' => "{$year}-12-31",
                'is_active' => true,
            ]);

            $allocation->update([
                'status' => 'billed',
                'billed_charge_id' => $charge->id,
            ]);

            return $allocation->refresh();
        });
    }
}
