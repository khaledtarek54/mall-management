<?php

namespace App\Console\Commands;

use App\Settings\AccountingSettings;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * Delete activity-log rows older than the operator's retention period (EG-34).
 *
 * ## Why this exists instead of scheduling `activitylog:clean` with `--days`
 *
 * The retention period is a SETTING, and a setting lives in the database. Passing it as a scheduled
 * command's argument would read it while the schedule is being DEFINED — `routes/console.php` is
 * loaded by every artisan invocation, including `migrate` on a database that has no settings table
 * yet and every test boot. Reading it at RUN time is the only version that is both current and safe.
 *
 * ## `0` means keep everything, and it must not be confused with "no answer"
 *
 * An operator who decides to keep the log for ever sets 0. That is a decision, so it is reported —
 * a silent no-op looks identical to a broken schedule, which is exactly how the six-times-repeated
 * posting-date bug in this codebase kept surviving.
 */
class PruneActivityLogCommand extends Command
{
    protected $signature = 'atriom:prune-activity-log {--dry-run : Count what would be deleted without deleting it}';

    protected $description = 'Delete activity-log rows older than the configured retention period';

    public function handle(): int
    {
        $days = (int) app(AccountingSettings::class)->activity_log_retention_days;

        if ($days <= 0) {
            $this->info('Activity-log retention is set to keep everything — nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days)->startOfDay();

        // Counted before deleting so the operator is told what happened, and so `--dry-run` can
        // answer the question a retention policy is actually reviewed on: how much would go.
        $doomed = Activity::query()->where('created_at', '<', $cutoff)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$doomed} activity rows older than {$cutoff->toDateString()} ({$days} days).");

            return self::SUCCESS;
        }

        Activity::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$doomed} activity rows older than {$cutoff->toDateString()} ({$days} days).");

        return self::SUCCESS;
    }
}
