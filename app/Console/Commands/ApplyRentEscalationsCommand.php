<?php

namespace App\Console\Commands;

use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ApplyRentEscalationsCommand extends Command
{
    protected $signature = 'leases:apply-escalations {--date= : YYYY-MM-DD, defaults to today}';

    protected $description = 'Apply due contractual rent escalations to active leases (stated percentage, stated amount, or CPI against the index register; idempotent, lock-safe).';

    public function handle(RentEscalationService $service): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $this->info("Applying rent escalations due on or before {$today->toDateString()}...");
        $stats = $service->runForToday($today);

        $this->table(
            // The label said "Skipped (CPI / 0% / not due)" until 2026-08-19, when CPI stopped
            // being categorically skipped and started resolving against the index register. A
            // column header that names a reason which is no longer a reason is the cheapest
            // possible way to mislead the person reading the nightly output.
            ['Considered', 'Applied', 'Skipped (no step due, or an index figure not published yet)', 'Failed'],
            [[$stats['considered'], $stats['applied'], $stats['skipped'], $stats['failed']]],
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
