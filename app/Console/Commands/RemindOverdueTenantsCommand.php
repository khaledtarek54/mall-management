<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Notifications\InvoiceOverdueTenantNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remind tenants about their own overdue (past-due, unpaid) invoices. Tenant
 * counterpart to ScanOverdueInvoicesCommand (which alerts Jawad owners); tracked
 * on a separate stamp — invoices.tenant_overdue_notified_at — so each overdue
 * invoice reminds the tenant exactly once, independently of the owner alert.
 */
class RemindOverdueTenantsCommand extends Command
{
    protected $signature = 'billing:remind-overdue-tenants {--dry-run : Print what would be reminded without writing}';

    protected $description = 'Remind tenants about their overdue invoices (idempotent via tenant_overdue_notified_at).';

    public function handle(): int
    {
        $overdue = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<', now())
            ->whereNull('tenant_overdue_notified_at')
            ->with('tenant')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No new overdue invoices.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would remind tenants on {$overdue->count()} overdue invoice(s):");
            foreach ($overdue as $invoice) {
                /** @var Tenant $tenant */
                $tenant = $invoice->tenant;
                $this->line(sprintf(
                    '  %s · %s · balance %s · due %s',
                    $invoice->number,
                    $tenant->name,
                    number_format((float) $invoice->balance, 2),
                    $invoice->due_date->format('Y-m-d'),
                ));
            }

            return self::SUCCESS;
        }

        $reminded = 0;
        foreach ($overdue as $invoice) {
            try {
                // Lock the invoice + re-check the stamp inside the transaction so an
                // overlapping scan can't remind the same tenant twice.
                $sent = DB::transaction(function () use ($invoice) {
                    $locked = Invoice::query()->lockForUpdate()->find($invoice->id);
                    if (! $locked || $locked->tenant_overdue_notified_at !== null) {
                        return false;
                    }

                    /** @var Tenant|null $tenant */
                    $tenant = $locked->tenant;
                    if (! $tenant) {
                        return false;
                    }

                    $tenant->notifyPortal(new InvoiceOverdueTenantNotification($locked));
                    $locked->forceFill(['tenant_overdue_notified_at' => now()])->save();

                    return true;
                });

                if ($sent) {
                    $reminded++;
                }
            } catch (\Throwable $e) {
                $this->warn("  failed on #{$invoice->id}: ".$e->getMessage());
            }
        }

        $this->info("Reminded tenants on {$reminded} of {$overdue->count()} overdue invoice(s).");

        return self::SUCCESS;
    }
}
