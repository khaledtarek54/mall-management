<?php

namespace App\Console\Commands;

use App\Services\GeneratePreventiveWorkOrdersService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Raises preventive-maintenance work orders for every due plan (module 26). Scheduled
 * daily; idempotent + lock-safe, so a re-run or an overlapping run is harmless.
 */
class GeneratePreventiveWorkOrdersCommand extends Command
{
    protected $signature = 'facility:generate-preventive {--date= : Generate as of this date (YYYY-MM-DD) instead of today}';

    protected $description = 'Raise preventive-maintenance work orders for plans that are due.';

    public function handle(GeneratePreventiveWorkOrdersService $service): int
    {
        $date = null;
        if ($this->option('date')) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->option('date'))) {
                $this->error('--date must be YYYY-MM-DD.');

                return self::FAILURE;
            }
            $date = Carbon::parse($this->option('date'))->toDateString();
        }

        $count = $service->run($date);
        $this->info("Raised {$count} preventive-maintenance work order(s).");

        // The service contains a per-plan failure so the rest of the portfolio still gets
        // its work orders — but a silently skipped plan is a plan nobody is maintaining.
        // Report it, and exit non-zero so the scheduler's output is actionable.
        if ($service->failures !== []) {
            $this->newLine();
            $this->error(count($service->failures).' plan(s) failed and raised nothing:');

            foreach ($service->failures as $planId => $reason) {
                $this->warn("  plan #{$planId}: {$reason}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
