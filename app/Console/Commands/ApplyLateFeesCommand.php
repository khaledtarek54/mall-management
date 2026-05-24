<?php

namespace App\Console\Commands;

use App\Jobs\ApplyLateFees;
use App\Services\LateFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ApplyLateFeesCommand extends Command
{
    protected $signature = 'billing:apply-late-fees {--date= : YYYY-MM-DD, defaults to today} {--queue : Dispatch to the queue instead of running synchronously}';

    protected $description = 'Apply late fees to overdue invoices (idempotent; configurable grace period and percentage).';

    public function handle(LateFeeService $service): int
    {
        $dateOption = $this->option('date');

        if ($this->option('queue')) {
            ApplyLateFees::dispatch($dateOption);
            $this->info('Late-fee job dispatched' . ($dateOption ? " for {$dateOption}" : '') . '.');
            return self::SUCCESS;
        }

        $today = $dateOption
            ? CarbonImmutable::parse($dateOption)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $this->info("Applying late fees as of {$today->toDateString()}...");
        $stats = $service->runForToday($today);

        $this->table(
            ['Considered', 'Applied', 'Skipped (already)', 'Failed'],
            [[$stats['considered'], $stats['applied'], $stats['skipped'], $stats['failed']]],
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
