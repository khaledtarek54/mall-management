<?php

namespace App\Console\Commands;

use App\Models\MaintenanceWorkOrder;
use App\Notifications\WorkOrderResponseSlaBreachedNotification;
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
 * Distinct from `requests:scan-sla-breaches`, which covers module 11's tenant-facing
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
        // --dry-run writes NOTHING, and backfilling a deadline is a write. Same reasoning that
        // already keeps penalty assessment out of the preview.
        $healed = $this->option('dry-run')
            ? $this->countMissingClocks()
            : $this->stampMissingClocks();

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

        // --dry-run is documented as "print what would be alerted WITHOUT writing", so it must
        // return before BOTH writes — the alerts AND the penalty assessment. assessPenalties()
        // creates/updates real maintenance_penalties (financial) rows, so it cannot run in a
        // preview (gap-analysis F-96). Preview first, then return.
        if ($this->option('dry-run')) {
            $breached = $overdue->whereNull('sla_breach_notified_at');
            foreach ($breached as $order) {
                $this->line("  would alert: {$order->reference} ({$order->hoursOverSla()}h over)");
            }

            $unanswered = MaintenanceWorkOrder::query()
                ->responseBreached()->whereNull('response_breach_notified_at')->get();
            foreach ($unanswered as $order) {
                $this->line("  would alert (unanswered): {$order->reference} ({$order->hoursOverResponseSla()}h over)");
            }

            $this->info("{$breached->count()} breach(es) and {$unanswered->count()} unanswered job(s) would be alerted; {$overdue->count()} penalty assessment(s) would run.");
            $this->info("{$healed} pre-existing order(s) would have their SLA clocks stamped.");

            return self::SUCCESS;
        }

        $assessFailures = $this->assessPenalties($overdue);

        // The response pass runs whether or not anything breached its RESOLUTION target — they are
        // independent clocks, and putting this after the early return below would have meant a
        // quiet week for resolutions silenced every unanswered job too.
        [$responseAlerted, $responseFailures] = $this->alertResponseBreaches();

        $breached = $overdue->whereNull('sla_breach_notified_at');

        if ($breached->isEmpty()) {
            $this->info("No new SLA breaches. Alerted {$responseAlerted} unanswered job(s).");
            $this->summarise($overdue->count(), $assessFailures, $responseAlerted, $responseFailures);

            return self::SUCCESS;
        }

        $alerted = $responseAlerted;
        $alertFailures = $responseFailures;

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
     * Heal corrective orders that predate the response clock (2026-08-12).
     *
     * The deadlines are stamped by `MaintenanceWorkOrder::creating`, so every new order has them.
     * Rows created BEFORE that — including every job that slipped through the original defect —
     * would otherwise stay invisible forever, which is the exact failure this fixes. Done here
     * rather than in the migration on purpose: the targets resolve through the three-tier
     * `SlaResolver`, i.e. through live settings and per-property policies, and a migration that
     * reads application state is a migration that breaks the day that state changes shape.
     *
     * Idempotent — `stampSlaClocks()` only ever fills a null — and `saveQuietly` because backfilling
     * a deadline is not an operator edit and has no place in the activity log.
     */
    /** How many rows `stampMissingClocks()` would touch — the preview half, which writes nothing. */
    private function countMissingClocks(): int
    {
        return $this->missingClocksQuery()->count();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<MaintenanceWorkOrder> */
    private function missingClocksQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return MaintenanceWorkOrder::query()
            ->corrective()
            ->whereNull('target_response_at')
            ->whereNotIn('status', MaintenanceWorkOrder::TERMINAL);
    }

    private function stampMissingClocks(): int
    {
        $healed = 0;

        $this->missingClocksQuery()
            ->chunkById(200, function ($orders) use (&$healed) {
                foreach ($orders as $order) {
                    try {
                        $order->stampSlaClocks();
                        if ($order->isDirty()) {
                            $order->saveQuietly();
                            $healed++;
                        }
                    } catch (\Throwable $e) {
                        OpsLog::error('Could not stamp SLA clocks on a legacy work order', [
                            'work_order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($healed > 0) {
            $this->info("Stamped SLA clocks on {$healed} pre-existing order(s).");
            OpsLog::info('Backfilled SLA clocks on legacy work orders', ['count' => $healed]);
        }

        return $healed;
    }

    /**
     * Nobody has taken the job on, and the response window has passed.
     *
     * This is the half that closes the trapdoor. FR-CM-07 starts the RESOLUTION clock at
     * acceptance so an engineer is not charged for queue time — which left queue time
     * accountable to nobody at all. An order sitting unanswered is now a breach in its own
     * right, alerted once, with its own stamp so it cannot silence (or be silenced by) the
     * resolution breach.
     *
     * @return array{0: int, 1: int} alerted, failures
     */
    private function alertResponseBreaches(): array
    {
        $rows = MaintenanceWorkOrder::query()
            ->responseBreached()
            ->whereNull('response_breach_notified_at')
            ->get();

        $alerted = 0;
        $failures = 0;

        foreach ($rows as $row) {
            try {
                $alerted += $this->alertResponseBreach($row->id) ? 1 : 0;
            } catch (\Throwable $e) {
                $failures++;
                $this->warn("  response alert failed on #{$row->id}: {$e->getMessage()}");
                OpsLog::error('Work-order response-SLA alert failed', [
                    'work_order_id' => $row->id,
                    'reference' => $row->reference,
                    'asset_id' => $row->asset_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$alerted, $failures];
    }

    private function alertResponseBreach(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            /** @var MaintenanceWorkOrder|null $order */
            $order = MaintenanceWorkOrder::whereKey($orderId)->with('equipment')->lockForUpdate()->first();

            // Re-checked inside the lock, same as the resolution alert: two overlapping runs would
            // otherwise both read null and alert twice. Acceptance between the query and the lock
            // means it was answered after all.
            if (! $order
                || $order->response_breach_notified_at !== null
                || $order->acknowledged_at !== null
                || $order->isTerminal()) {
                return false;
            }

            $staff = app(AssetStaffRecipients::class);
            $recipients = $staff->for($order->asset_id, ['manager', 'operations'])
                ->merge($staff->owners($order->asset_id))
                ->unique('id')
                ->values();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new WorkOrderResponseSlaBreachedNotification($order));
            }

            $order->forceFill(['response_breach_notified_at' => now()])->save();

            return true;
        });
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

            // Owner Jawad gets the oversight alert on a late FACILITY job too (FR MNT-5 / NOT-2),
            // exactly as the tenant-request breach scan does — a late corrective job on the
            // building is the "late maintenance → notify Jawad" event the FRD names, and omitting
            // owners here (while module 11 includes them) was an inconsistency, not a decision.
            $staff = app(AssetStaffRecipients::class);
            $recipients = $staff->for($order->asset_id, ['manager', 'operations'])
                ->merge($staff->owners($order->asset_id))
                ->unique('id')
                ->values();

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
