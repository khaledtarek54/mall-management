<?php

namespace App\Console\Commands;

use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ApplyRentEscalationsCommand extends Command
{
    protected $signature = 'leases:apply-escalations {--date= : YYYY-MM-DD, defaults to today}';

    protected $description = 'Apply due contractual rent escalations to active leases (fixed_percent; idempotent, lock-safe).';

    public function handle(RentEscalationService $service): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $this->info("Applying rent escalations due on or before {$today->toDateString()}...");
        $stats = $service->runForToday($today);

        $this->table(
            ['Considered', 'Applied', 'Skipped (CPI / 0% / not due)', 'Failed'],
            [[$stats['considered'], $stats['applied'], $stats['skipped'], $stats['failed']]],
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
