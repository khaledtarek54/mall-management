<?php

namespace App\Console\Commands;

use App\Models\MaintenanceRequest;
use App\Notifications\MaintenanceSlaBreachedNotification;
use App\Services\AssetStaffRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class ScanMaintenanceSlaBreachesCommand extends Command
{
    protected $signature = 'maintenance:scan-sla-breaches {--dry-run : Print what would be alerted without writing}';

    protected $description = 'Notify operators about open maintenance requests whose target_resolution_at has passed (idempotent via sla_breach_notified_at).';

    public function handle(): int
    {
        $breached = MaintenanceRequest::query()
            ->whereIn('status', MaintenanceRequest::OPEN_STATUSES)
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
        foreach ($breached as $request) {
            try {
                $assetId = $request->unit?->asset_id;
                $service = app(AssetStaffRecipients::class);
                $recipients = $service->for($assetId, ['manager', 'maintenance_manager'])
                    // Jawad owners get the oversight alert too (FR MNT-5).
                    ->merge($service->owners($assetId))
                    ->unique('id')
                    ->values();

                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new MaintenanceSlaBreachedNotification($request));
                    $request->forceFill(['sla_breach_notified_at' => now()])->save();
                    $alerted++;
                }
            } catch (\Throwable $e) {
                $this->warn("  failed on #{$request->id}: ".$e->getMessage());
            }
        }

        $this->info("Alerted on {$alerted} of {$breached->count()} breach(es).");

        return self::SUCCESS;
    }
}
