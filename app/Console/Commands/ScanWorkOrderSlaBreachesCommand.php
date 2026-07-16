<?php

namespace App\Console\Commands;

use App\Models\MaintenanceWorkOrder;
use App\Notifications\WorkOrderSlaBreachedNotification;
use App\Services\AssetStaffRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Flags corrective jobs whose SLA has expired (FR-CM-08, detection half) — module 26.
 *
 * Distinct from `maintenance:scan-sla-breaches`, which covers module 11's tenant-facing
 * requests. Same shape deliberately: idempotent via a `sla_breach_notified_at` stamp,
 * re-checked under a row lock inside the transaction, and contained per row so one bad
 * record can't halt the scan for every property.
 */
class ScanWorkOrderSlaBreachesCommand extends Command
{
    protected $signature = 'maintenance:scan-wo-sla-breaches {--dry-run : Print what would be alerted without writing}';

    protected $description = 'Notify operators about open corrective work orders whose SLA target has passed (idempotent via sla_breach_notified_at).';

    public function handle(): int
    {
        $breached = MaintenanceWorkOrder::query()
            ->corrective()
            ->open()
            ->whereNotNull('target_resolution_at')
            ->where('target_resolution_at', '<', now())
            ->whereNull('sla_breach_notified_at')
            ->with(['asset', 'equipment', 'vendor'])
            ->get();

        if ($breached->isEmpty()) {
            $this->info('No new SLA breaches.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($breached as $order) {
                $this->line("  would alert: {$order->reference} ({$order->hoursOverSla()}h over)");
            }

            $this->info("{$breached->count()} breach(es) would be alerted.");

            return self::SUCCESS;
        }

        $alerted = 0;

        foreach ($breached as $row) {
            try {
                $alerted += $this->alertBreach($row->id) ? 1 : 0;
            } catch (\Throwable $e) {
                // Per-row containment: one unreachable recipient or bad row must not stop
                // every other property's breaches being raised.
                $this->warn("  failed on #{$row->id}: {$e->getMessage()}");
            }
        }

        $this->info("Alerted {$alerted} SLA breach(es).");

        return self::SUCCESS;
    }

    /** Named alertBreach(), not alert(): Command::alert() already exists as a public banner helper. */
    private function alertBreach(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            // Re-fetched under the lock, so the eager loads from the outer query are gone —
            // reload them here or the notification lazy-loads equipment per breach.
            /** @var MaintenanceWorkOrder|null $order */
            $order = MaintenanceWorkOrder::whereKey($orderId)->with('equipment')->lockForUpdate()->first();

            // Re-check the stamp INSIDE the transaction: two overlapping runs (a slow scan
            // still going when the next fires) would otherwise both read null and alert twice.
            if (! $order || $order->sla_breach_notified_at !== null || $order->isTerminal()) {
                return false;
            }

            $recipients = app(AssetStaffRecipients::class)->for($order->asset_id, ['manager', 'operations']);

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new WorkOrderSlaBreachedNotification($order));
            }

            // Stamped even when nobody is assigned to the property — otherwise a mall with
            // no staff would re-alert on every run forever.
            $order->forceFill(['sla_breach_notified_at' => now()])->save();

            return true;
        });
    }
}
