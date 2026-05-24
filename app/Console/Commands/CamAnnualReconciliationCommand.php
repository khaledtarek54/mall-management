<?php

namespace App\Console\Commands;

use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Illuminate\Console\Command;

class CamAnnualReconciliationCommand extends Command
{
    protected $signature = 'cam:reconcile {--year= : YYYY, defaults to previous calendar year} {--auto-bill : Also bill all generated allocations (default: review-only)}';

    protected $description = 'Generate CAM allocations for all expense pools of a given year. Idempotent.';

    public function handle(CamReconciliationService $service): int
    {
        $year = (int) ($this->option('year') ?: (now()->year - 1));

        $pools = CamExpensePool::query()
            ->where('period_year', $year)
            ->whereIn('status', ['draft', 'reconciling'])
            ->get();

        if ($pools->isEmpty()) {
            $this->warn("No CAM pools found for {$year}.");
            return self::SUCCESS;
        }

        $totalAllocations = 0;
        $totalBilled = 0;
        $autoBill = (bool) $this->option('auto-bill');

        foreach ($pools as $pool) {
            $count = $service->generateAllocations($pool);
            $totalAllocations += $count;
            $this->info("Pool #{$pool->id} ({$pool->period_year} · {$pool->asset?->name}) — {$count} allocations.");

            if ($autoBill) {
                foreach ($pool->allocations()->where('status', 'pending')->get() as $allocation) {
                    $service->bill($allocation);
                    $totalBilled++;
                }
            }
        }

        $this->info("Done. {$totalAllocations} allocations generated" . ($autoBill ? ", {$totalBilled} billed." : '. Review and bill from the admin panel.'));

        return self::SUCCESS;
    }
}
