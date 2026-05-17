<?php

namespace App\Console\Commands;

use App\Jobs\RunMonthlyBilling;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RunMonthlyBillingCommand extends Command
{
    protected $signature = 'billing:run-monthly {--period= : YYYY-MM, defaults to current month} {--queue : Dispatch the job instead of running synchronously}';

    protected $description = 'Generate monthly invoices for all active leases (idempotent per lease+period).';

    public function handle(MonthlyBillingService $service): int
    {
        $periodOption = $this->option('period');

        if ($this->option('queue')) {
            RunMonthlyBilling::dispatch($periodOption);
            $this->info('Monthly billing job dispatched' . ($periodOption ? " for {$periodOption}" : '') . '.');
            return self::SUCCESS;
        }

        $period = $periodOption
            ? CarbonImmutable::createFromFormat('Y-m', $periodOption)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $this->info("Running monthly billing for {$period->format('F Y')}...");
        $stats = $service->runForPeriod($period);

        $this->table(
            ['Period', 'Considered', 'Created', 'Skipped', 'Failed'],
            [[$stats['period'], $stats['leases_considered'], $stats['created'], $stats['skipped'], $stats['failed']]]
        );

        if ($stats['failed'] > 0) {
            $this->warn('Failed lease IDs: ' . implode(', ', $stats['failed_lease_ids']));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
