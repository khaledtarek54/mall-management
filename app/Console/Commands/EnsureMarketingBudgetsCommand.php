<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\MarketingBudget;
use Illuminate\Console\Command;

/**
 * Auto-provisions a marketing budget for every real property for the given year
 * (default: current year). Idempotent (firstOrCreate) — safe to run on a
 * schedule, so each new year's budgets appear automatically and a newly-added
 * property gets one too. Users never hand-create budgets; they record spends.
 */
class EnsureMarketingBudgetsCommand extends Command
{
    protected $signature = 'marketing:ensure-budgets {--year= : Year to ensure (defaults to the current year)}';

    protected $description = 'Ensure every property has a marketing budget for the year (auto-provisioned)';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: date('Y'));
        $count = 0;

        Asset::query()
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->each(function (Asset $asset) use ($year, &$count) {
                MarketingBudget::forPeriod($asset->id, $year);
                $count++;
            });

        $this->info("Ensured marketing budgets for {$count} property(ies) for {$year}.");

        return self::SUCCESS;
    }
}
