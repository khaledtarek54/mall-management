<?php

namespace App\Console\Commands;

use App\Services\CamReconciliationService;
use Illuminate\Console\Command;

class CamAnnualReconciliationCommand extends Command
{
    protected $signature = 'cam:reconcile {--year= : YYYY, defaults to previous calendar year} {--auto-bill : Also bill all generated allocations and mark pools reconciled (default: review-only)}';

    protected $description = 'Generate CAM allocations for every expense pool of a given year. Idempotent. With --auto-bill, runs the full true-up lifecycle end-to-end.';

    public function handle(CamReconciliationService $service): int
    {
        $year = (int) ($this->option('year') ?: (now()->year - 1));
        $autoBill = (bool) $this->option('auto-bill');

        $report = $service->autoTrueUpForYear($year, $autoBill);

        if (empty($report)) {
            $this->warn("No CAM pools found for {$year}.");
            return self::SUCCESS;
        }

        foreach ($report as $row) {
            $line = "Pool #{$row['pool_id']} ({$row['asset']}) — {$row['allocations']} allocations";
            if ($autoBill) {
                $line .= ", {$row['billed']} billed";
            }
            $line .= " · status: {$row['status']}";
            $this->info($line);
        }

        $totalAllocations = array_sum(array_column($report, 'allocations'));
        $totalBilled = array_sum(array_column($report, 'billed'));

        $this->info(
            "Done. {$totalAllocations} allocations generated" .
            ($autoBill ? ", {$totalBilled} billed." : '. Review and bill from the admin panel.'),
        );

        return self::SUCCESS;
    }
}
