<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Notifications\InvoiceOverdueOwnerNotification;
use App\Services\AssetStaffRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Notify Jawad owners when a tenant is late paying an invoice on a property
 * they own. Mirrors the maintenance SLA scan: idempotent through
 * invoices.owner_overdue_notified_at, so each overdue invoice alerts once.
 */
class ScanOverdueInvoicesCommand extends Command
{
    protected $signature = 'billing:scan-overdue-invoices {--dry-run : Print what would be alerted without writing}';

    protected $description = 'Notify Jawad owners about overdue (late-paid) invoices on their properties (idempotent via owner_overdue_notified_at).';

    public function handle(): int
    {
        $overdue = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            // COLLECTABLE, not `balance`: a partial write-off leaves the invoice live with its
            // whole balance standing, so this chased the operator's own forgiveness.
            ->whereCollectable()
            ->whereDate('due_date', '<', now())
            ->whereNull('owner_overdue_notified_at')
            ->with(['lease.unit', 'tenant'])
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No new overdue invoices.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would alert owners on {$overdue->count()} overdue invoice(s):");
            foreach ($overdue as $invoice) {
                $this->line(sprintf(
                    '  %s · %s · balance %s · due %s',
                    $invoice->number,
                    $invoice->tenant?->name ?? '—',
                    number_format((float) $invoice->balance, 2),
                    $invoice->due_date?->format('Y-m-d') ?? '—',
                ));
            }

            return self::SUCCESS;
        }

        $alerted = 0;
        foreach ($overdue as $invoice) {
            try {
                // Lock the invoice + re-check the stamp inside the transaction so
                // an overlapping/concurrent scan can't notify the owner twice.
                $sent = DB::transaction(function () use ($invoice) {
                    $locked = Invoice::query()->lockForUpdate()->find($invoice->id);
                    if (! $locked || $locked->owner_overdue_notified_at !== null) {
                        return false;
                    }

                    // `asset_id`, not the lease chain: for a unit-owner assessment the chain is null,
                    // `owners(null)` is empty, and the method returns BELOW without stamping
                    // `owner_overdue_notified_at` — so an overdue assessment was re-locked and re-skipped
                    // every night for ever and the owner was never told.
                    $owners = app(AssetStaffRecipients::class)->owners($locked->asset_id);
                    if ($owners->isEmpty()) {
                        return false;
                    }

                    Notification::send($owners, new InvoiceOverdueOwnerNotification($locked));
                    $locked->forceFill(['owner_overdue_notified_at' => now()])->save();

                    return true;
                });

                if ($sent) {
                    $alerted++;
                }
            } catch (\Throwable $e) {
                $this->warn("  failed on #{$invoice->id}: ".$e->getMessage());
            }
        }

        $this->info("Alerted owners on {$alerted} of {$overdue->count()} overdue invoice(s).");

        return self::SUCCESS;
    }
}
