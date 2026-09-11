<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\TenantSalesDeclaration;
use App\Notifications\SalesDeclarationReminderNotification;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Remind tenants who OWE a sales declaration and have NOT submitted one for a closed period.
 *
 * A tenant who never uploads a report otherwise escapes their percentage rent silently — no
 * declaration row exists, so nothing bills and nothing alerts (the reporting-layer twin of the
 * billing-gap leak). This scans active leases that owe a declaration for the target month
 * (`Lease::requiresSalesReporting()` — the percentage-rent clause unless the lease says otherwise;
 * SW-254 made this command read it), were billable in it (commenced, past their fit-out grace) and
 * have no declaration for it, and reminds the tenant. Idempotent: the reminder carries the
 * (lease, period) so re-running never re-notifies.
 * Companion to the admin "missing sales declarations" ActionRequired card (which surfaces the same
 * set live, so the leak is never silent again).
 */
class ScanMissingSalesDeclarationsCommand extends Command
{
    protected $signature = 'sales:scan-missing-declarations
        {--period= : First-of-month YYYY-MM-01 to scan; defaults to the previous month}
        {--dry-run : Print who would be reminded without sending}';

    protected $description = 'Remind tenants who owe a sales declaration and have not filed one for a closed period (idempotent).';

    public function handle(): int
    {
        // Lock-safe (scheduled-scan invariant): serialize with a concurrent manual run — withoutOverlapping
        // only guards scheduler-vs-scheduler. Without a stampable row (a missing declaration has none),
        // an atomic cache lock is the analogue to the sibling scans' lockForUpdate + stamp.
        $lock = Cache::lock('sales:scan-missing-declarations', 600);
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
            // The ONE definition of "the last month a declaration can exist for", shared with
            // `sales:estimate-missing` and with the two reports that divide sales into cost by it.
            : TenantSalesDeclaration::lastDeclarableMonth();
        $periodKey = $periodStart->format('Y-m');
        // Localised: the label is what the tenant reads in the reminder's own sentence, and
        // `format('F Y')` is English whatever the locale (the `BillingRefusal` trap).
        $periodLabel = $periodStart->locale(app()->getLocale())->isoFormat('MMMM YYYY');

        // One definition of "owes a declaration", shared with the estimate, the month-end close
        // checklist and the dashboard card — see Lease::missingSalesDeclarationsFor().
        $leases = Lease::missingSalesDeclarationsFor(CarbonImmutable::instance($periodStart));

        if ($leases->isEmpty()) {
            $this->info("No missing sales declarations for {$periodLabel}.");

            return self::SUCCESS;
        }

        // The finding is recorded BEFORE anything is delivered (SW-244's rule, SW-252's instance):
        // this scan runs ONCE a month and its next run scans the next month, so a finding that
        // lives only in the delivery has exactly one chance to survive the transport. On 10 Sep
        // 2026 it did not, and nothing on the box said the scan had run at all. The finding is who
        // is MISSING, not who was chased — so it is logged before the idempotency loop, and a manual
        // re-run of the same period records the same finding again, which is what a finding is.
        if (! $this->option('dry-run')) {
            OpsLog::warning('sales.declarations_missing', [
                'period' => $periodKey,
                'count' => $leases->count(),
                'leases' => $leases->pluck('reference')->all(),
            ]);
        }

        $reminded = 0;
        $skipped = 0;

        foreach ($leases as $lease) {
            $tenant = $lease->tenant;
            if (! $tenant instanceof Tenant) {
                continue;
            }

            // Idempotency: a reminder for this exact (lease, period) already sent → don't re-nag.
            // The same record the estimate now requires before it bills (SW-253) — one definition.
            if ($lease->salesDeclarationRemindedAt($periodKey) !== null) {
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
            $this->warn("Would remind {$leases->count()} tenant(s) for {$periodLabel} (".$leases->count().' missing).');

            return self::SUCCESS;
        }

        $this->info("Reminded {$reminded} tenant(s) for {$periodLabel}; {$skipped} already reminded.");

        return self::SUCCESS;
    }
}
