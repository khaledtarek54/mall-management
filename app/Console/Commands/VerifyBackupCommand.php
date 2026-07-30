<?php

namespace App\Console\Commands;

use App\Services\Backup\VerifyBackupService;
use App\Support\OpsLog;
use Illuminate\Console\Command;

/**
 * Restore drill: prove the newest backup can actually be restored.
 *
 * `backup:run` writes archives and `backup:monitor` notices when they go stale, but neither ever
 * opens one. This does — weekly, so a broken backup is found during a drill instead of during an
 * incident.
 *
 * Exits non-zero on failure so a scheduler or an operator running it by hand gets a real signal.
 */
class VerifyBackupCommand extends Command
{
    protected $signature = 'atriom:backup-verify
        {--database= : Scratch database to restore into (default: <app database>__restore_check)}
        {--keep : Leave the scratch database in place for inspection}';

    protected $description = 'Restore the newest backup into a scratch database and verify it is usable';

    public function handle(VerifyBackupService $service): int
    {
        $this->info('Verifying the newest backup archive…');

        $result = $service->verify(
            scratchDatabase: $this->option('database') ?: null,
            keep: (bool) $this->option('keep'),
        );

        if ($result['archive'] !== null) {
            $this->line('  archive : '.$result['archive']);
            $this->line('  age     : '.$result['archive_age_hours'].'h');
            $this->line('  size    : '.number_format((int) $result['archive_bytes'] / 1048576, 1).' MB');
        }

        if ($result['skipped']) {
            $this->warn('Skipped — '.$result['reason']);

            return self::SUCCESS;
        }

        if (! $result['ok']) {
            $this->error('BACKUP NOT RESTORABLE — '.$result['reason']);

            // OpsLog, not a bespoke mail notification: this is an ops failure, and OpsLog is
            // already the channel that reaches Slack/Sentry once OPS_LOG_STACK is set. A mail
            // notification here would default to backup.notifications.mail.to, which falls back
            // to a placeholder @atriom.invalid address — i.e. it would send into the void and
            // look like it had alerted someone.
            OpsLog::error('backup.verify.failed', [
                'archive' => $result['archive'],
                'reason' => $result['reason'],
            ]);

            return self::FAILURE;
        }

        $this->line('  tables  : '.$result['tables']);

        foreach ($result['rows'] as $table => $count) {
            $this->line(sprintf('    %-22s %s', $table, number_format($count)));
        }

        if ($this->option('keep')) {
            $this->comment('Scratch database kept: '.$result['scratch']);
        }

        $this->info('Restorable ✓ — '.$result['reason']);

        return self::SUCCESS;
    }

}
