<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\MarketingBudget;
use App\Services\MarketingLevyService;
use Illuminate\Console\Command;

/**
 * Rebuilds marketing budgets by accruing the levy (% of base rent) across all
 * historically billed invoices (FR MKT-5). Idempotent — sets accrued_amount
 * per (asset, year) rather than incrementing, so it's safe to re-run.
 */
class BackfillMarketingBudgetsCommand extends Command
{
    protected $signature = 'marketing:backfill-budgets';

    protected $description = 'Rebuild marketing budgets by accruing the levy on all historically billed base rent';

    public function handle(MarketingLevyService $svc): int
    {
        $rate = $svc->ratePercent();
        $totals = [];

        Invoice::with(['items' => fn ($q) => $q->where('type', 'base_rent'), 'lease.unit'])
            ->chunkById(200, function ($invoices) use (&$totals) {
                foreach ($invoices as $invoice) {
                    $rent = (float) $invoice->items->sum('amount');
                    $assetId = $invoice->lease?->unit?->asset_id;
                    $year = $invoice->period_start?->year;

                    if ($rent <= 0 || ! $assetId || ! $year) {
                        continue;
                    }

                    $totals[$assetId][$year] = ($totals[$assetId][$year] ?? 0) + $rent;
                }
            });

        $count = 0;
        foreach ($totals as $assetId => $years) {
            foreach ($years as $year => $rent) {
                MarketingBudget::forPeriod((int) $assetId, (int) $year)
                    ->update(['accrued_amount' => round($rent * $rate / 100, 2)]);
                $count++;
            }
        }

        $this->info("Rebuilt {$count} marketing budget(s) from billed base rent at {$rate}%.");

        return self::SUCCESS;
    }
}
