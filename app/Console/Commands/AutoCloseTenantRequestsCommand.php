<?php

namespace App\Console\Commands;

use App\Models\TenantRequest;
use App\Services\TenantRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoCloseTenantRequestsCommand extends Command
{
    protected $signature = 'requests:auto-close
        {--days= : Override config(requests.auto_close_after_days)}
        {--dry-run : Print what would change without writing}';

    protected $description = 'Transition resolved TenantRequest rows older than auto_close_after_days to status=closed (audit M09 F-38 / D-30).';

    public function handle(TenantRequestService $service): int
    {
        $days = (int) ($this->option('days') ?? config('requests.auto_close_after_days', 7));

        if ($days < 1) {
            $this->error('--days must be ≥ 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $candidates = TenantRequest::query()
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
                // Lock + re-check inside the txn so an overlapping run (or a manual
                // close between the query and here) is skipped cleanly rather than
                // throwing / double-notifying.
                $applied = DB::transaction(function () use ($service, $request) {
                    $locked = TenantRequest::query()->lockForUpdate()->find($request->id);

                    if (! $locked || $locked->status !== 'resolved') {
                        return false;
                    }

                    $service->transition($locked, 'closed');

                    return true;
                });

                if ($applied) {
                    $closed++;
                }
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
