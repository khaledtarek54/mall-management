<?php

namespace App\Listeners;

use App\Support\OpsLog;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * A failed backup must leave a trace that does not depend on anyone having set an env var.
 *
 * **The gap this closes.** spatie routes `BackupHasFailedNotification` through
 * `config/backup.php`'s notification channels, and that list is built as
 * `$backupAlertEmail ? ['mail'] : []`. With `BACKUP_ALERT_EMAIL` unset — which is the shipped
 * default — the failure notification goes to **no channel at all**. Nothing else was listening,
 * so a backup could fail every night in complete silence.
 *
 * That is not hypothetical. On the machine this was written on there is no `mysqldump` binary, so
 * `backup:run` exits 127 (`sh: mysqldump: command not found`) and had been producing nothing since
 * the schedule was added on 2026-07-29. Three layers would each have caught it — `/health`'s
 * backup-freshness check, the spatie notification, `backup:monitor`'s exit code — and all three
 * were inert: nothing polls `/health` yet, no channel is configured, and nobody reads scheduler
 * exit codes.
 *
 * So the signal is moved somewhere that always exists. `OpsLog::error` writes to the ops log
 * unconditionally and reaches Slack/Sentry the moment `OPS_LOG_STACK`/`SENTRY_LARAVEL_DSN` are
 * set — no configuration is required for the record to be *made*, only for it to page someone.
 *
 * Note what is NOT logged at error level: a successful backup. Logging every success is how a log
 * stops being read, which is the same failure in a different costume.
 *
 * The methods are named `when*`, NOT `handle*`, on purpose. Laravel auto-discovers any `handle*`
 * method in app/Listeners and registers it by type-hint — which, alongside the explicit
 * Event::subscribe() in AppServiceProvider, registered every one of these TWICE and logged each
 * failure twice. Registration here is explicit and singular; a duplicate error line is how an
 * error log starts getting skimmed.
 */
class LogBackupFailures
{
    public function whenBackupFailed(BackupHasFailed $event): void
    {
        OpsLog::error('backup.run.failed', [
            'disk' => $event->diskName,
            'backup' => $event->backupName,
            'error' => $event->exception->getMessage(),
            // The most common cause by far, and invisible from the message alone: the dump binary
            // is missing from the box, so the archive is written without a database (or not at all).
            'hint' => 'check that mysqldump is installed on the machine running the scheduler',
        ]);
    }

    public function whenCleanupFailed(CleanupHasFailed $event): void
    {
        // Cleanup failing is how a disk fills, and a full disk stops the app rather than slowing
        // it — MySQL, sessions and uploads all lose their writes at once.
        OpsLog::error('backup.cleanup.failed', [
            'disk' => $event->diskName,
            'backup' => $event->backupName,
            'error' => $event->exception->getMessage(),
        ]);
    }

    public function whenUnhealthyBackupFound(UnhealthyBackupWasFound $event): void
    {
        OpsLog::error('backup.unhealthy', [
            'disk' => $event->diskName,
            'backup' => $event->backupName,
            'failures' => $event->failureMessages
                ->map(fn (array $f): string => ($f['check'] ?? '?').': '.($f['message'] ?? ''))
                ->all(),
        ]);
    }

    public function whenBackupSucceeded(BackupWasSuccessful $event): void
    {
        // Info, not error — this exists so "when did backups last work?" is answerable from the
        // ops log without hunting, not so that success pages anyone.
        OpsLog::info('backup.run.succeeded', [
            'disk' => $event->diskName,
            'backup' => $event->backupName,
        ]);
    }

    /** @return array<class-string, string> */
    public function subscribe(): array
    {
        return [
            BackupHasFailed::class => 'whenBackupFailed',
            CleanupHasFailed::class => 'whenCleanupFailed',
            UnhealthyBackupWasFound::class => 'whenUnhealthyBackupFound',
            BackupWasSuccessful::class => 'whenBackupSucceeded',
        ];
    }
}
