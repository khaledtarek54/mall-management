<?php

namespace App\Console\Commands;

use App\Settings\AccountingSettings;
use Illuminate\Console\Command;
use Spatie\Activitylog\Support\Config;

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

        // The CONFIGURED activity model, not `Spatie\...\Activity` by name: the package lets an
        // application swap it, and a pruner that queried the wrong class would delete from a table
        // nothing else writes to and report success.
        $model = Config::activityModelInstance();

        $expired = fn () => $model::query()->where('created_at', '<', $cutoff);

        // Counted before deleting so the operator is told what happened, and so `--dry-run` can
        // answer the question a retention policy is actually reviewed on: how much would go.
        $doomed = $expired()->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$doomed} activity rows older than {$cutoff->toDateString()} ({$days} days).");

            return self::SUCCESS;
        }

        // Deleted in CHUNKS. Spatie's own action issues one unbounded `DELETE`, which is fine for a
        // library and not for a job that runs unattended at 05:00 on the first of the month: five
        // years of a real portfolio's audit trail is millions of rows, and one statement holds locks
        // on the table for as long as it takes. `DemoSeeder` alone writes 1,287 rows for one mall.
        do {
            $deleted = $expired()->limit(1000)->delete();
        } while ($deleted > 0);

        $this->info("Deleted {$doomed} activity rows older than {$cutoff->toDateString()} ({$days} days).");

        return self::SUCCESS;
    }
}
