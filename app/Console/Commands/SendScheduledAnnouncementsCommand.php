<?php

namespace App\Console\Commands;

use App\Jobs\BroadcastAnnouncement;
use App\Models\Announcement;
use App\Services\SendAnnouncementAction;
use App\Support\OpsLog;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Broadcast the notices whose scheduled time has arrived.
 *
 * Scheduling is the reason `status` exists. Before it, composing an announcement WAS sending it,
 * so the Ramadan-hours notice could only be written on the morning it had to go out — by whoever
 * happened to be at a desk, in whatever language they typed fastest. A notice written a fortnight
 * early, in both languages, reviewed by someone, is a different quality of communication, and the
 * only thing standing between the two was this sweep.
 *
 * **Idempotent and lock-safe**, per the scheduled-scan invariant: each row is re-checked inside its
 * own locked transaction, so two overlapping runs cannot both broadcast the same notice. The
 * `sent_at` guard inside {@see SendAnnouncementAction} is the second backstop, and the recipient
 * table's unique key is the third — a notice cannot double-notify a tenant even if both of the
 * first two were somehow bypassed.
 *
 * The send runs INLINE here rather than dispatching {@see BroadcastAnnouncement}. The
 * scheduler is already off the request thread, and dispatching would put the fan-out beyond the
 * lock that makes this safe.
 */
class SendScheduledAnnouncementsCommand extends Command
{
    protected $signature = 'announcements:send-scheduled {--dry-run : Print what would be sent without broadcasting}';

    protected $description = 'Broadcast scheduled announcements whose publish time has arrived (module 27).';

    public function handle(SendAnnouncementAction $sender): int
    {
        $due = Announcement::query()->dueToSend()->orderBy('publish_at');

        $ids = (clone $due)->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No scheduled announcements are due.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would broadcast {$ids->count()} announcement(s):");

            // withTrashed on the eager load: `Asset` soft-deletes, and a notice in a retired mall
            // would otherwise print "—" for the one field saying WHICH mall is about to be
            // messaged.
            (clone $due)
                ->with(['asset' => fn ($q) => $q->withTrashed()->select('id', 'code')])
                ->get()
                ->each(fn (Announcement $a) => $this->line(sprintf(
                    '  #%d %s · %s · due %s',
                    $a->id,
                    $a->asset->code,
                    $a->title,
                    $a->publish_at?->format('Y-m-d H:i') ?? '—',
                )));

            return self::SUCCESS;
        }

        $sent = 0;
        $reached = 0;
        /** @var array<int, string> $failures */
        $failures = [];

        foreach ($ids as $id) {
            // Re-read + re-check INSIDE the lock. The candidate list was taken outside it, so by
            // now another worker may have sent this notice or an operator may have sent it by hand
            // from the panel.
            $announcement = DB::transaction(function () use ($id) {
                /** @var Announcement|null $locked */
                $locked = Announcement::query()->whereKey($id)->lockForUpdate()->first();

                if ($locked === null || ! $locked->isScheduled() || $locked->sent_at !== null) {
                    return null;
                }

                return $locked;
            });

            if ($announcement === null) {
                continue;
            }

            // **PER NOTICE, because one refusal must not silence the sweep.** `handle()` refuses a
            // notice whose window has already shut (SW-151), and this loop had no catch — so ONE
            // such row threw, every notice behind it in the same run went unsent, and the row stayed
            // `scheduled` with a past `publish_at`. `dueToSend()` returns it on every run and the
            // sweep is ordered by `publish_at`, so it came first every time: the command is
            // scheduled every fifteen minutes, and every scheduled announcement in the system would
            // have stopped, permanently, ~96 silent failures a day with nothing alerting.
            //
            // Exactly the shape `GenerateRecurringExpensesService` already records for its own
            // per-schedule catch, and for the same reason: a poison row must cost its own delivery
            // and nothing else.
            try {
                $reached += $sender->handle($announcement);
                $sent++;
            } catch (DomainException $e) {
                $failures[$announcement->id] = $e->getMessage();
            }
        }

        if ($failures !== []) {
            // Reported, not swallowed — a caught exception nobody is told about is the same silent
            // failure in a quieter coat, and the operator's only other signal is a notice that never
            // arrives. Non-zero exit so the scheduler's own failure hook fires.
            OpsLog::error('announcements: notices refused', [
                'count' => count($failures),
                'failures' => $failures,
            ]);

            foreach ($failures as $id => $message) {
                $this->error("Announcement #{$id}: {$message}");
            }
        }

        $this->info("Broadcast {$sent} announcement(s) to {$reached} tenant(s).");

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
