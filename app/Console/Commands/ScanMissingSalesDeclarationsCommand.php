<?php

namespace App\Console\Commands;

use App\Models\Lease;
use Carbon\CarbonImmutable;
use App\Notifications\SalesDeclarationReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Remind percentage-rent tenants who have NOT submitted a sales declaration for a closed period.
 *
 * A tenant who never uploads a report otherwise escapes their percentage rent silently — no
 * declaration row exists, so nothing bills and nothing alerts (the reporting-layer twin of the
 * billing-gap leak). This scans active `has_percentage_rent` leases that were billable in the
 * target month (commenced, past their fit-out grace) and have no declaration for it, and reminds
 * the tenant. Idempotent: the reminder carries the (lease, period) so re-running never re-notifies.
 * Companion to the admin "missing sales declarations" ActionRequired card (which surfaces the same
 * set live, so the leak is never silent again).
 */
class ScanMissingSalesDeclarationsCommand extends Command
{
    protected $signature = 'sales:scan-missing-declarations
        {--period= : First-of-month YYYY-MM-01 to scan; defaults to the previous month}
        {--dry-run : Print who would be reminded without sending}';

    protected $description = 'Remind percentage-rent tenants with a missing sales declaration for a closed period (idempotent).';

    public function handle(): int
    {
        // Lock-safe (scheduled-scan invariant): serialize with a concurrent manual run — withoutOverlapping
        // only guards scheduler-vs-scheduler. Without a stampable row (a missing declaration has none),
        // an atomic cache lock is the analogue to the sibling scans' lockForUpdate + stamp.
        $lock = \Illuminate\Support\Facades\Cache::lock('sales:scan-missing-declarations', 600);
        if (! $lock->get()) {
            $this->warn('Another sales-declaration scan is already running.');

            return self::SUCCESS;
        }

        try {
            return $this->scan();
        } finally {
            $lock->release();
        }
    }

    private function scan(): int
    {
        $periodStart = $this->option('period')
            ? Carbon::parse($this->option('period'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $periodKey = $periodStart->format('Y-m');
        $periodLabel = $periodStart->format('F Y');

        // One definition of "owes a declaration", shared with the month-end close checklist —
        // see Lease::missingSalesDeclarationsFor().
        $leases = Lease::missingSalesDeclarationsFor(
            CarbonImmutable::instance($periodStart),
            CarbonImmutable::instance($periodEnd),
        );

        if ($leases->isEmpty()) {
            $this->info("No missing percentage-rent declarations for {$periodLabel}.");

            return self::SUCCESS;
        }

        $reminded = 0;
        $skipped = 0;

        foreach ($leases as $lease) {
            $tenant = $lease->tenant;
            if (! $tenant instanceof \App\Models\Tenant) {
                continue;
            }

            // Idempotency: a reminder for this exact (lease, period) already sent → don't re-nag.
            $already = $tenant->notifications()
                ->where('data->type', 'sales_declaration_reminder')
                ->where('data->lease_id', $lease->id)
                ->where('data->period_key', $periodKey)
                ->exists();
            if ($already) {
                $skipped++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  would remind %s · %s · %s', $tenant->name, $lease->reference, $periodLabel));
                continue;
            }

            // Don't let one tenant's bad email (tenants.email is nullable + the mail channel sends
            // in-process) abort the whole scan — that would re-create the silent leak this closes.
            try {
                $tenant->notifyPortal(new SalesDeclarationReminderNotification($lease, $periodLabel, $periodKey));
                $reminded++;
            } catch (\Throwable $e) {
                $this->warn("  reminder failed for {$tenant->name} ({$lease->reference}): {$e->getMessage()}");
            }
        }

        if ($this->option('dry-run')) {
            $this->warn("Would remind {$leases->count()} tenant(s) for {$periodLabel} (".$leases->count()." missing).");

            return self::SUCCESS;
        }

        $this->info("Reminded {$reminded} tenant(s) for {$periodLabel}; {$skipped} already reminded.");

        return self::SUCCESS;
    }
}
