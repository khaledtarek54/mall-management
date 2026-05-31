<?php

namespace App\Console\Commands;

use App\Models\MaintenanceRequest;
use App\Services\MaintenanceRequestService;
use Illuminate\Console\Command;

class AutoCloseMaintenanceRequestsCommand extends Command
{
    protected $signature = 'maintenance:auto-close
        {--days= : Override config(maintenance.auto_close_after_days)}
        {--dry-run : Print what would change without writing}';

    protected $description = 'Transition resolved MaintenanceRequest rows older than auto_close_after_days to status=closed (audit M09 F-38 / D-30).';

    public function handle(MaintenanceRequestService $service): int
    {
        $days = (int) ($this->option('days') ?? config('maintenance.auto_close_after_days', 7));

        if ($days < 1) {
            $this->error('--days must be ≥ 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $candidates = MaintenanceRequest::query()
            ->where('status', 'resolved')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $cutoff)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("No resolved maintenance requests older than {$days} days.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would close {$candidates->count()} maintenance request(s):");
            foreach ($candidates as $request) {
                $this->line(sprintf(
                    '  #%d %s · %s · resolved %s',
                    $request->id,
                    $request->reference ?: '—',
                    $request->title,
                    $request->resolved_at->format('Y-m-d'),
                ));
            }

            return self::SUCCESS;
        }

        $closed = 0;
        $failed = [];
        foreach ($candidates as $request) {
            try {
                $service->transition($request, 'closed');
                $closed++;
            } catch (\Throwable $e) {
                $failed[] = "#{$request->id}: " . $e->getMessage();
            }
        }

        $this->info("Closed {$closed} of {$candidates->count()} maintenance request(s).");

        if ($failed) {
            $this->warn('Failed to close:');
            foreach ($failed as $row) {
                $this->line('  ' . $row);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
