<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Notifications\InvoiceOverdueTenantNotification;
use App\Settings\BillingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Chase tenants about their own overdue (past-due, unpaid) invoices — the dunning ladder (1A-16).
 * Tenant counterpart to ScanOverdueInvoicesCommand (which alerts Jawad owners), tracked on its own
 * stamp so the two fire independently.
 *
 * **It used to chase exactly once, for the life of the invoice.** The query filtered on
 * `whereNull(tenant_overdue_notified_at)` and the stamp was set, so a tenant three months behind had
 * been written to as often as one three days behind — once — and no second notice, final demand or
 * notice count existed anywhere. Everything after that first email was somebody's memory.
 *
 * Now the invoice carries `dunning_level` (the notice NUMBER) beside that stamp (the date of the
 * LAST notice), and two settings decide the cadence:
 *
 *   - `dunning_followup_days` — days since the last notice before chasing again. **0 = chase once**,
 *     which is how it ships, so nothing changes on deploy.
 *   - `dunning_max_notices` — the ceiling. The notice AT the ceiling is the final demand and reads
 *     differently (see {@see InvoiceOverdueTenantNotification}). 0 = no ceiling, and therefore no
 *     final demand, because a last notice needs a last.
 *
 * The ladder is per INVOICE rather than per tenant, deliberately: each invoice is its own claim with
 * its own age, a tenant may be current on one and months behind on another, and a per-tenant counter
 * would send a final demand about a bill raised yesterday.
 */
class RemindOverdueTenantsCommand extends Command
{
    protected $signature = 'billing:remind-overdue-tenants {--dry-run : Print what would be reminded without writing}';

    protected $description = 'Chase tenants about their overdue invoices, on the configured dunning cadence.';

    public function handle(): int
    {
        $settings = app(BillingSettings::class);
        $followUpDays = max(0, (int) $settings->dunning_followup_days);
        $maxNotices = max(0, (int) $settings->dunning_max_notices);

        $due = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            // Same reduction as the owner-facing sweep — and this one writes to the TENANT, so
            // chasing a forgiven slice is a letter asking for money nobody is owed.
            ->whereCollectable()
            ->whereDate('due_date', '<', now())
            ->where(function ($q) use ($followUpDays, $maxNotices) {
                // Never chased — the first notice, and the only branch that existed before.
                $q->whereNull('tenant_overdue_notified_at');

                // Chased before, and due to be chased again. Only when a cadence is configured:
                // with 0 the whole branch is skipped, which is what keeps the shipped behaviour
                // identical rather than merely similar.
                if ($followUpDays > 0) {
                    $q->orWhere(fn ($f) => $f
                        ->whereNotNull('tenant_overdue_notified_at')
                        ->where('tenant_overdue_notified_at', '<=', now()->subDays($followUpDays))
                        ->when($maxNotices > 0, fn ($c) => $c->where('dunning_level', '<', $maxNotices)));
                }
            })
            ->with('tenant')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No tenant is due a notice.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would chase {$due->count()} overdue invoice(s):");
            foreach ($due as $invoice) {
                /** @var Tenant $tenant */
                $tenant = $invoice->tenant;
                $next = (int) $invoice->dunning_level + 1;
                $this->line(sprintf(
                    '  %s · %s · balance %s · due %s · notice #%d%s',
                    $invoice->number,
                    $tenant?->name ?? '—',
                    number_format((float) $invoice->balance, 2),
                    $invoice->due_date->format('Y-m-d'),
                    $next,
                    $maxNotices > 0 && $next >= $maxNotices ? ' (FINAL)' : '',
                ));
            }

            return self::SUCCESS;
        }

        $sentCount = 0;
        foreach ($due as $invoice) {
            try {
                // Lock the invoice + re-read the level inside the transaction so an overlapping run
                // cannot send the same notice twice, and so the level written is the one this run
                // actually read (not a value carried across the wait).
                $sent = DB::transaction(function () use ($invoice, $followUpDays, $maxNotices) {
                    $locked = Invoice::query()->lockForUpdate()->find($invoice->id);

                    if (! $locked) {
                        return false;
                    }

                    $level = (int) $locked->dunning_level;
                    $lastNotice = $locked->tenant_overdue_notified_at;

                    // Re-check the whole decision under the lock, not just "has it ever been sent".
                    if ($lastNotice !== null) {
                        if ($followUpDays <= 0) {
                            return false;
                        }
                        if ($lastNotice->greaterThan(now()->subDays($followUpDays))) {
                            return false;
                        }
                        if ($maxNotices > 0 && $level >= $maxNotices) {
                            return false;
                        }
                    }

                    /** @var Tenant|null $tenant */
                    $tenant = $locked->tenant;
                    if (! $tenant) {
                        return false;
                    }

                    $next = $level + 1;
                    $isFinal = $maxNotices > 0 && $next >= $maxNotices;

                    $tenant->notifyPortal(new InvoiceOverdueTenantNotification($locked, $next, $isFinal));
                    $locked->forceFill([
                        'tenant_overdue_notified_at' => now(),
                        'dunning_level' => $next,
                    ])->save();

                    return true;
                });

                if ($sent) {
                    $sentCount++;
                }
            } catch (\Throwable $e) {
                $this->warn("  failed on #{$invoice->id}: ".$e->getMessage());
            }
        }

        $this->info("Chased {$sentCount} of {$due->count()} overdue invoice(s).");

        return self::SUCCESS;
    }
}
