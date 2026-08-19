<?php

namespace App\Console\Commands;

use App\Models\WorkPermit;
use App\Notifications\WorkPermitOverdueNotification;
use App\Services\AssetStaffRecipients;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Permits whose window has passed and which nobody closed out.
 *
 * **This is the whole safety control, not a tidiness report.** An issued permit that was never
 * closed means no one recorded that the welding stopped, the isolation was removed and the area was
 * checked. It is the first thing an insurer or a safety auditor looks for, and — like the PDC
 * coverage gap — it is invisible on every screen that shows what EXISTS, because the missing thing
 * is a closure that was never written.
 *
 * Hourly, not daily: a permit is bounded to the hour, so a daily sweep could leave hazardous work
 * unaccounted for most of a day. Idempotent by construction — it reports a state, writes nothing to
 * the permit, and notifies the property's own team.
 */
class ScanOpenWorkPermitsCommand extends Command
{
    protected $signature = 'facility:scan-open-permits {--date= : ISO datetime, defaults to now}';

    protected $description = 'Report permits to work whose validity has passed without being closed out.';

    public function handle(AssetStaffRecipients $recipients): int
    {
        $at = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : CarbonImmutable::now();

        $overdue = WorkPermit::query()
            ->overdueClosure($at)
            ->with(['asset', 'vendor'])
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No permits are past their window unclosed.');

            return self::SUCCESS;
        }

        // Off-box, because a safety finding that only exists in an in-app bell is a finding nobody
        // sees on the weekend it matters. Logged and PRINTED before anything is delivered: a
        // transport that refuses (a rate-limited mail provider, a 429) must not be able to swallow
        // the finding on its way out — that was observed, not imagined.
        OpsLog::warning('work_permit.overdue_closure', [
            'count' => $overdue->count(),
            'as_of' => $at->toDateTimeString(),
        ]);

        $this->warn("{$overdue->count()} permit(s) past their window without closure:");
        $this->table(
            ['Reference', 'Type', 'Contractor', 'Expired'],
            $overdue->map(fn (WorkPermit $p): array => [
                $p->reference,
                $p->type,
                $p->vendor?->name ?? $p->contractor_name ?? '—',
                $p->valid_to?->toDateTimeString(),
            ])->all(),
        );

        foreach ($overdue->groupBy('asset_id') as $assetId => $permits) {
            $staff = $recipients->for((int) $assetId, ['manager', 'operations']);

            if ($staff->isNotEmpty()) {
                Notification::send($staff, new WorkPermitOverdueNotification($permits->count(), (int) $assetId));
            }
        }

        return self::SUCCESS;
    }
}
