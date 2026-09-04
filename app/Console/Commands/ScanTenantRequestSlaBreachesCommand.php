<?php

namespace App\Console\Commands;

use App\Models\TenantRequest;
use App\Notifications\TenantRequestSlaBreachedNotification;
use App\Services\AssetStaffRecipients;
use App\Support\OpsLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Alerts operators about tenant requests whose SLA target has passed (module 11).
 *
 * **Every outcome goes through OpsLog, not just `$this->info()`** — this runs on the
 * scheduler and `routes/console.php` captures no console output, so console-only lines are
 * discarded. Per-row containment means a failure is silent by design (the scan continues and
 * returns SUCCESS), so the summary below is the only evidence that a breach went unalerted.
 * Mirrors ScanWorkOrderSlaBreachesCommand and MonthlyBillingService.
 */
class ScanTenantRequestSlaBreachesCommand extends Command
{
    protected $signature = 'requests:scan-sla-breaches {--dry-run : Print what would be alerted without writing}';

    protected $description = 'Notify operators about open maintenance requests whose target_resolution_at has passed (idempotent via sla_breach_notified_at).';

    public function handle(): int
    {
        $breached = TenantRequest::query()
            ->whereIn('status', TenantRequest::OPEN_STATUSES)
            ->whereNotNull('target_resolution_at')
            ->where('target_resolution_at', '<', now())
            ->whereNull('sla_breach_notified_at')
            ->with(['unit.asset', 'tenant'])
            ->get();

        if ($breached->isEmpty()) {
            $this->info('No new SLA breaches.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would alert on {$breached->count()} breach(es):");
            foreach ($breached as $request) {
                $this->line(sprintf(
                    '  #%d %s · %s · priority %s · target %s',
                    $request->id,
                    $request->reference ?: '—',
                    $request->title,
                    $request->priority,
                    $request->target_resolution_at?->format('Y-m-d H:i') ?? '—',
                ));
            }

            return self::SUCCESS;
        }

        $alerted = 0;
        $failures = 0;
        foreach ($breached as $request) {
            try {
                // Lock the request + re-check the stamp inside the transaction so
                // an overlapping/concurrent scan can't alert the same breach twice.
                $sent = DB::transaction(function () use ($request) {
                    $locked = TenantRequest::query()->lockForUpdate()->find($request->id);
                    if (! $locked || $locked->sla_breach_notified_at !== null) {
                        return false;
                    }

                    $assetId = $locked->unit?->asset_id;
                    $service = app(AssetStaffRecipients::class);
                    $recipients = $service->for($assetId, ['manager', 'operations'])
                        // Jawad owners get the oversight alert too (FR MNT-5).
                        ->merge($service->owners($assetId))
                        ->unique('id')
                        ->values();

                    if ($recipients->isEmpty()) {
                        return false;
                    }

                    $locked->forceFill(['sla_breach_notified_at' => now()])->save();

                    // After the commit, never under the lock (SW-213). This notification is not
                    // `ShouldQueue` and its `via()` is `['mail', 'database']`, so sent inside the
                    // transaction the X lock on `tenant_requests` is held across one synchronous
                    // MailerSend round-trip per recipient — on the board a technician is working.
                    DB::afterCommit(fn () => Notification::send($recipients, new TenantRequestSlaBreachedNotification($locked)));

                    return true;
                });

                if ($sent) {
                    $alerted++;
                }
            } catch (\Throwable $e) {
                $failures++;
                $this->warn("  failed on #{$request->id}: ".$e->getMessage());
                OpsLog::error('Maintenance SLA breach alert failed', [
                    'request_id' => $request->id,
                    'reference' => $request->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Alerted on {$alerted} of {$breached->count()} breach(es).");

        OpsLog::info('Maintenance SLA scan complete', [
            'breached' => $breached->count(),
            'alerted' => $alerted,
        ]);

        if ($failures > 0) {
            OpsLog::warning('Maintenance SLA scan had failures', [
                'failures' => $failures,
                'breached' => $breached->count(),
            ]);
        }

        return self::SUCCESS;
    }
}
