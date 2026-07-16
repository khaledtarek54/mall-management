<?php

namespace App\Console\Commands;

use App\Models\MaintenanceWorkOrder;
use App\Notifications\WorkOrderSlaBreachedNotification;
use App\Services\AssessSlaPenaltyService;
use App\Services\AssetStaffRecipients;
use App\Support\OpsLog;
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
 *
 * **Why every outcome goes through OpsLog, not just `$this->info()`.** This runs HOURLY on
 * the scheduler, and `routes/console.php` captures no console output — so anything written
 * with `$this->warn()` alone is discarded. Per-row containment (below) means a failure here
 * is silent by design: the scan keeps going and returns SUCCESS. That is correct for
 * availability and unacceptable for money — a swallowed `assess()` failure means a vendor is
 * never charged for missing its SLA, and nobody finds out. The console lines stay for a human
 * running this by hand; OpsLog is what survives the scheduler. Mirrors MonthlyBillingService.
 */
class ScanWorkOrderSlaBreachesCommand extends Command
{
    protected $signature = 'maintenance:scan-wo-sla-breaches {--dry-run : Print what would be alerted without writing}';

    protected $description = 'Notify operators about open corrective work orders whose SLA target has passed (idempotent via sla_breach_notified_at).';

    public function handle(): int
    {
        // Two different jobs, two different queries, deliberately.
        //
        // The ALERT is once per order — `sla_breach_notified_at` is its key.
        // The PENALTY may ACCRUE, so it must be re-assessed on every run for as long as the
        // job stays late. Driving both off the alert stamp would either charge a per-day
        // penalty once, or re-charge it hourly. AssessSlaPenaltyService keeps one row per
        // order and updates it, so re-running is free.
        $overdue = MaintenanceWorkOrder::query()
            ->corrective()
            ->open()
            ->whereNotNull('target_resolution_at')
            ->where('target_resolution_at', '<', now())
            ->with(['asset', 'equipment', 'vendor'])
            ->get();

        $assessFailures = $this->assessPenalties($overdue);

        $breached = $overdue->whereNull('sla_breach_notified_at');

        if ($breached->isEmpty()) {
            $this->info('No new SLA breaches.');
            $this->summarise($overdue->count(), $assessFailures, alerted: 0, alertFailures: 0);

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
        $alertFailures = 0;

        foreach ($breached as $row) {
            try {
                $alerted += $this->alertBreach($row->id) ? 1 : 0;
            } catch (\Throwable $e) {
                // Per-row containment: one unreachable recipient or bad row must not stop
                // every other property's breaches being raised.
                $alertFailures++;
                $this->warn("  failed on #{$row->id}: {$e->getMessage()}");
                OpsLog::error('Work-order SLA breach alert failed', [
                    'work_order_id' => $row->id,
                    'reference' => $row->reference,
                    'asset_id' => $row->asset_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Alerted {$alerted} SLA breach(es).");
        $this->summarise($overdue->count(), $assessFailures, $alerted, $alertFailures);

        return self::SUCCESS;
    }

    /**
     * One durable line per run, plus a loud one when anything failed. The scan deliberately
     * exits SUCCESS even with failures (per-row containment), so this summary is the only
     * signal that a penalty went unassessed or a breach unalerted.
     */
    private function summarise(int $overdue, int $assessFailures, int $alerted, int $alertFailures): void
    {
        OpsLog::info('Work-order SLA scan complete', [
            'overdue' => $overdue,
            'penalties_assessed' => $overdue - $assessFailures,
            'alerted' => $alerted,
        ]);

        if ($assessFailures > 0 || $alertFailures > 0) {
            OpsLog::warning('Work-order SLA scan had failures', [
                'penalty_assessment_failures' => $assessFailures,
                'alert_failures' => $alertFailures,
                'overdue' => $overdue,
            ]);
        }
    }

    /**
     * Re-assess every overdue job's penalty (FR-CM-08). Contained per row: a penalty that
     * can't be computed must not stop the breach ALERTS going out — the alert is the part
     * an engineer acts on.
     *
     * A failure here is MONEY: the vendor is not charged for missing its SLA. Containment
     * makes that silent, so each one is logged individually and counted into the summary.
     *
     * @param  \Illuminate\Support\Collection<int, MaintenanceWorkOrder>  $overdue
     * @return int the number of orders whose penalty could not be assessed
     */
    private function assessPenalties($overdue): int
    {
        $service = app(AssessSlaPenaltyService::class);
        $failures = 0;

        foreach ($overdue as $order) {
            try {
                $service->assess($order);
            } catch (\Throwable $e) {
                $failures++;
                $this->warn("  penalty assessment failed on #{$order->id}: {$e->getMessage()}");
                OpsLog::error('SLA penalty assessment failed — vendor not charged', [
                    'work_order_id' => $order->id,
                    'reference' => $order->reference,
                    'asset_id' => $order->asset_id,
                    'vendor_id' => $order->vendor_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $failures;
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
