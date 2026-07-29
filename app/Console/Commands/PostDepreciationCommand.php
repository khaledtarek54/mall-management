<?php

namespace App\Console\Commands;

use App\Services\DepreciationService;
use App\Support\PostingDate;
use Carbon\CarbonImmutable;
use DomainException;
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

        // Each charge is dated at its period_month in the GL (DepreciationEntryJournalizer).
        // Depreciating into a CLOSED month would write charges whose entries can never post —
        // the register would show the month as depreciated while the books never saw it, and
        // because the run is idempotent a later re-run would skip it, making the gap permanent.
        // Only reachable via --month (the scheduler and the admin button both use now()), but
        // that is exactly the backfill someone reaches for after a close.
        try {
            PostingDate::assertOpen($period, '--month');
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $count = $service->run($period);

        $this->info("Posted depreciation for {$count} fixed asset(s) — {$period->format('M Y')}.");

        return self::SUCCESS;
    }
}
