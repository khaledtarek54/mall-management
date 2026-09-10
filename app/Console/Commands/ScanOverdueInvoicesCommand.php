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
 *
 * **It also keeps `invoices.status` honest about the calendar (SW-245, 2026-09-10).** `overdue` is
 * a PROJECTION — `Invoice::recomputeTotals()` derives it from the four settlement channels and the
 * due date — and that method runs when a SETTLEMENT lands, so an issued invoice nobody pays or
 * penalises goes on reading `issued` for ever. Measured on the staging soak: six invoices two days
 * past due, money on all six, all still `issued`; the four that did say `overdue` said it only
 * because the late-fee run had touched them. No money read the column (SW-135 routed collections
 * through `stillOwed()` + the date), but the register's status filter, its tabs and the tenant's
 * own portal view all do, and a screen that under-reports is a failure nobody files. This is the
 * `ProjectedState` shape exactly — `leases:expire` re-projects units for the same reason — and it
 * lives here rather than in a command of its own because this is the sweep that exists to notice
 * an invoice going past due. Both directions: `issued` → `overdue` when the date has passed, and
 * `overdue` → `issued` when a due date was EXTENDED as a concession (the field stays editable on a
 * live receivable for exactly that) and nothing recomputed. The projector is `recomputeTotals()`
 * itself, so there is no second definition of what the status should be.
 */
class ScanOverdueInvoicesCommand extends Command
{
    protected $signature = 'billing:scan-overdue-invoices {--dry-run : Print what would be alerted without writing}';

    protected $description = 'Notify Jawad owners about overdue (late-paid) invoices on their properties (idempotent via owner_overdue_notified_at).';

    public function handle(): int
    {
        $this->reprojectStatuses();

        $overdue = Invoice::query()
            ->chaseable()
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

                    $locked->forceFill(['owner_overdue_notified_at' => now()])->save();

                    // After the commit, never under the lock (SW-213). `invoices` is the most
                    // contended table in the system — every capture, credit-note application,
                    // deposit netting and write-off locks a row of it — and this alert's `via()` is
                    // `['database']` TODAY only: `AlsoSendsByMail` was added to fourteen
                    // notifications after they were written, so which channels a notification uses
                    // is not a property to build a lock's duration on.
                    DB::afterCommit(fn () => Notification::send($owners, new InvoiceOverdueOwnerNotification($locked)));

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

    /**
     * Re-run the status projection on every invoice whose stored status disagrees with the calendar.
     *
     * The candidate set is written against the SAME predicate the projector reads (`pastDue()` /
     * `isPastDue()`), so a second consecutive run finds nothing — which `ProjectedStateConformanceTest`
     * requires. Each row is re-read under a lock before the recompute: `recomputeTotals()` also
     * rewrites `paid_amount` and `balance`, and every settlement path locks the invoice first, so
     * the lock is what stops this sweep writing a figure read a moment before a receipt landed.
     */
    private function reprojectStatuses(): int
    {
        $candidates = Invoice::query()
            ->where(fn ($q) => $q
                ->where(fn ($stale) => $stale->where('status', 'issued')->pastDue())
                ->orWhere(fn ($stale) => $stale->where('status', 'overdue')->notPastDue()));

        $count = $candidates->count();

        if ($count === 0) {
            $this->info('No invoice status has gone stale.');

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would re-project {$count} invoice status(es):");
            $candidates->with('tenant:id,name')->get()->each(function (Invoice $invoice) {
                $this->line(sprintf(
                    '  %s · %s · %s · due %s',
                    $invoice->number,
                    $invoice->tenant?->name ?? '—',
                    $invoice->status,
                    $invoice->due_date?->format('Y-m-d') ?? '—',
                ));
            });

            return 0;
        }

        $moved = 0;
        foreach ($candidates->select('id')->get() as $row) {
            DB::transaction(function () use ($row, &$moved) {
                /** @var Invoice|null $locked */
                $locked = Invoice::query()->lockForUpdate()->find($row->id);

                // Re-checked under the lock for the window between the candidate read and here —
                // an act (void, dispute, write-off) landing in it must not be projected over. The
                // suite cannot open that window (sqlite compiles the lock to nothing), so this
                // clause is a control, not a tooth; the candidate query is what the tests bite on.
                if (! $locked || ! in_array($locked->status, ['issued', 'overdue'], true)) {
                    return;
                }

                $before = $locked->status;
                $locked->recomputeTotals();

                if ($locked->status !== $before) {
                    $moved++;
                }
            });
        }

        $this->info("Re-projected {$moved} invoice status(es).");

        return $moved;
    }
}
