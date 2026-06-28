<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Services\MarketingLevyService;
use Illuminate\Console\Command;

/**
 * Prepares existing data for the derived marketing-levy flow: seeds a marketing
 * levy Charge on every active lease that lacks one (so future billing includes
 * the tenant-facing levy line), then re-derives every budget's accrued (from
 * billed marketing items) and spent (from recorded spends). Idempotent.
 *
 * Historical invoices are NOT retro-charged the levy — accrued reflects only
 * marketing items actually billed, so prior-year budgets settle at what was
 * really billed (0 for periods before the levy line existed).
 */
class BackfillMarketingBudgetsCommand extends Command
{
    protected $signature = 'marketing:backfill-budgets';

    protected $description = 'Seed marketing levy charges on active leases + re-derive marketing budgets';

    public function handle(MarketingLevyService $svc): int
    {
        $seeded = 0;
        Lease::query()
            ->where('status', 'active')
            ->where('base_rent_monthly', '>', 0)
            ->whereDoesntHave('charges', fn ($q) => $q->where('type', 'marketing'))
            ->chunkById(200, function ($leases) use ($svc, &$seeded) {
                foreach ($leases as $lease) {
                    $svc->createLevyCharge($lease);
                    $seeded++;
                }
            });

        $recomputed = 0;
        MarketingBudget::query()->chunkById(200, function ($budgets) use (&$recomputed) {
            foreach ($budgets as $budget) {
                $budget->recomputeAccrued();
                $budget->recomputeSpent();
                $recomputed++;
            }
        });

        $this->info("Seeded {$seeded} marketing levy charge(s) on active leases; re-derived {$recomputed} budget(s).");

        return self::SUCCESS;
    }
}
