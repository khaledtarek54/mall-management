<?php

namespace App\Console\Commands;

use App\Services\DepreciationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Monthly straight-line depreciation for the fixed-asset register (module 23).
 * Idempotent (one charge per asset+month) and lock-safe — safe to re-run and to
 * schedule. See DepreciationService::run.
 */
class PostDepreciationCommand extends Command
{
    protected $signature = 'accounting:post-depreciation {--month= : Depreciate this month (YYYY-MM); defaults to the current month}';

    protected $description = 'Post the monthly straight-line depreciation charge for all active fixed assets (idempotent).';

    public function handle(DepreciationService $service): int
    {
        $month = $this->option('month');

        if ($month !== null && ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error("Invalid --month '{$month}'. Expected YYYY-MM.");

            return self::FAILURE;
        }

        $period = $month ? CarbonImmutable::parse($month.'-01') : CarbonImmutable::now();

        $count = $service->run($period);

        $this->info("Posted depreciation for {$count} fixed asset(s) — {$period->format('M Y')}.");

        return self::SUCCESS;
    }
}
