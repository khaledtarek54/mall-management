<?php

namespace App\Console\Commands;

use App\Models\LeaseOption;
use App\Notifications\LeaseOptionWindowNotification;
use App\Services\AssetStaffRecipients;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Alert on lease-option notice windows — before they open, before they close, and when one lapses.
 *
 * **The gap this closes.** Atriom's only lease-date alert was `leases:remind-expiring`, firing 90
 * days before EXPIRY. A typical clause reads "notice no earlier than 12 and no later than 9 months
 * before expiry", so by the time that reminder spoke the window had been shut for three to six
 * months. The system reliably told the leasing team about a lease only after they had lost the
 * right to do anything about it.
 *
 * Three moments, because each needs a different action:
 *  - **opening** — notice may now be served; start the conversation.
 *  - **closing** — the deadline is near; decide.
 *  - **lapsed** — it is gone; record it so the option stops appearing as live.
 *
 * Idempotent + lock-safe, the scheduled-scan invariant: each option is row-locked and its stamp
 * re-checked INSIDE the transaction, so a manual run racing the scheduled one cannot double-notify.
 */
class ScanLeaseOptionWindowsCommand extends Command
{
    protected $signature = 'leases:scan-option-windows
        {--lead= : Days of warning before each boundary (default from config)}
        {--dry-run : Print what would be alerted without writing or sending}';

    protected $description = 'Alert on lease-option notice windows opening, closing or lapsing (idempotent).';

    public function handle(): int
    {
        $today = CarbonImmutable::now()->startOfDay();
        $lead = (int) ($this->option('lead') ?? config('billing.lease_option_notice_lead_days', 30));
        $dryRun = (bool) $this->option('dry-run');

        $stats = ['opening' => 0, 'closing' => 0, 'lapsed' => 0, 'skipped' => 0, 'failed' => 0];

        $candidates = LeaseOption::query()
            ->where('status', 'open')
            // An option on a lease that is no longer live is not actionable.
            ->whereHas('lease', fn ($q) => $q->where('status', 'active'))
            ->with(['lease.tenant', 'lease.unit'])
            ->get();

        foreach ($candidates as $option) {
            try {
                $event = $this->eventFor($option, $today, $lead);

                if ($event === null) {
                    $stats['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  would alert [%s] %s · %s · closes %s',
                        $event,
                        $option->lease?->reference ?? "lease #{$option->lease_id}",
                        $option->type,
                        $option->latest_notice_date?->format('d/m/Y') ?? '—',
                    ));
                    $stats[$event]++;

                    continue;
                }

                $this->apply($option, $event) ? $stats[$event]++ : $stats['skipped']++;
            } catch (Throwable $e) {
                // Per-row containment: one bad option can't stop the sweep (mirrors the SLA scans).
                $stats['failed']++;
                OpsLog::error('lease_option_scan.failed', ['option_id' => $option->id, 'error' => $e->getMessage()]);
            }
        }

        $this->table(
            ['Opening', 'Closing', 'Lapsed', 'Skipped', 'Failed'],
            [[$stats['opening'], $stats['closing'], $stats['lapsed'], $stats['skipped'], $stats['failed']]],
        );

        if ($dryRun) {
            $this->warn('Dry run — nothing was written or sent.');
        } else {
            OpsLog::info('Lease option windows scanned', $stats);
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Which moment this option is at, or null when there is nothing to say.
     *
     * Checked lapsed-first: an option whose deadline has passed is past caring about its opening.
     *
     * @return 'opening'|'closing'|'lapsed'|null
     */
    private function eventFor(LeaseOption $option, CarbonImmutable $today, int $lead): ?string
    {
        if ($option->windowHasClosed($today)) {
            return $option->lapsed_notified_at === null ? 'lapsed' : null;
        }

        $daysToClose = $option->daysUntilClose($today);
        if ($daysToClose !== null && $daysToClose <= $lead && $option->closing_notified_at === null) {
            return 'closing';
        }

        // Opening: within the lead time of the earliest date, or already open and never announced.
        if ($option->opening_notified_at === null && $option->earliest_notice_date) {
            $earliest = CarbonImmutable::instance($option->earliest_notice_date)->startOfDay();
            if ($today->diffInDays($earliest, false) <= $lead) {
                return 'opening';
            }
        }

        return null;
    }

    /** @return bool true when an alert was actually sent */
    private function apply(LeaseOption $option, string $event): bool
    {
        return DB::transaction(function () use ($option, $event) {
            /** @var LeaseOption|null $fresh */
            $fresh = LeaseOption::whereKey($option->id)->lockForUpdate()->first();

            // Re-check the stamp under the lock — the whole point of the pattern. Between the
            // query above and this line another run may have notified and stamped.
            $stampColumn = $event.'_notified_at';
            if (! $fresh || $fresh->status !== 'open' || $fresh->{$stampColumn} !== null) {
                return false;
            }

            $updates = [$stampColumn => now()];

            // A lapsed option stops being live. Recording it is the point: an option that shows as
            // open forever is noise, and it would keep encumbering a unit that is free to let.
            if ($event === 'lapsed') {
                $updates['status'] = 'lapsed';
                $updates['resolved_at'] = now()->toDateString();
            }

            $fresh->forceFill($updates)->save();
            $fresh->loadMissing('lease.tenant', 'lease.unit');

            // Delivery failures warn but still stamp. The ActionRequired card reads the window
            // live and independently of these stamps, so a dropped email cannot make a deadline
            // invisible — the same rule the vendor-contract renewal scan follows.
            // After the commit, never under the lock (SW-213). The whole block moves, not just the
            // send: resolving recipients is itself two queries, and the `catch` has to travel with
            // the `try` or a deferred failure escapes the containment this scan depends on.
            DB::afterCommit(function () use ($fresh, $event) {
                try {
                    $recipients = app(AssetStaffRecipients::class)
                        ->for($fresh->lease?->unit?->asset_id, ['manager', 'leasing']);

                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, new LeaseOptionWindowNotification($fresh, $event));
                    }
                } catch (Throwable $e) {
                    OpsLog::warning('lease_option_scan.delivery_failed', [
                        'option_id' => $fresh->id, 'error' => $e->getMessage(),
                    ]);
                }
            });

            return true;
        });
    }
}
