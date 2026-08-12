<?php

namespace App\Console\Commands;

use App\Models\SavedReport;
use App\Services\Reports\DeliverSavedReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sends the saved reports that are due today.
 *
 * Nothing in this system emailed a report. The month-end pack was assembled by an operator opening
 * six screens, exporting six CSVs and attaching them to a mail on a day they had to remember —
 * which means it arrived late in the months somebody was on leave, and not at all in the months
 * somebody left.
 *
 * **Idempotent, because the scheduler is not.** `last_delivered_on` is stamped inside the same
 * transaction that claims the row, under a lock, and re-checked there — the pattern every scheduled
 * scan in this codebase uses. A retry, a catch-up after downtime, or two workers must not send a
 * month-end pack three times: that is how an operator learns to filter the sender.
 *
 * **One failure does not stop the run.** A month-end morning is exactly when the other five reports
 * matter. Each is caught and reported; nothing retries, because the schedule comes round again and
 * a report delivered twice is worse than one delivered late.
 */
class DeliverScheduledReportsCommand extends Command
{
    protected $signature = 'reports:deliver {--date= : Run as if it were this date} {--dry-run : List what would be sent}';

    protected $description = 'Email the saved reports scheduled for today';

    public function handle(DeliverSavedReportService $service): int
    {
        $on = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : CarbonImmutable::now();

        $due = SavedReport::query()
            ->whereNotNull('frequency')
            ->catalogued()
            ->with('user')
            ->get()
            ->filter(fn (SavedReport $saved) => $saved->isDueOn($on));

        if ($due->isEmpty()) {
            $this->info('No saved reports are due today.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($due as $saved) {
            if ($this->option('dry-run')) {
                $this->line("would send: {$saved->name} → ".implode(', ', $saved->recipients ?? []));
                $sent++;

                continue;
            }

            try {
                // Claim it first. The stamp is what makes a second run a no-op, so it has to be
                // written under the lock and re-checked inside the transaction — otherwise two
                // workers both read "not sent today" and both send.
                $claimed = DB::transaction(function () use ($saved, $on): bool {
                    $locked = SavedReport::whereKey($saved->getKey())->lockForUpdate()->first();

                    if (! $locked || ! $locked->isDueOn($on)) {
                        return false;
                    }

                    $locked->update(['last_delivered_on' => $on->toDateString()]);

                    return true;
                });

                if (! $claimed) {
                    continue;
                }

                if ($service->deliver($saved)) {
                    $sent++;
                    $this->line("sent: {$saved->name}");
                } else {
                    // Claimed but not deliverable — the owner lost access, the report left the
                    // catalogue, or it has no renderer. The stamp stays: retrying within the same
                    // day would fail identically and only add noise.
                    $failed++;
                    $this->warn("skipped (not deliverable): {$saved->name}");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("failed: {$saved->name} — {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Delivered {$sent}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
