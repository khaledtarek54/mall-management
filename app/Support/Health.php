<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What "up" actually means for this application.
 *
 * The stock `/up` returns 200 as long as PHP can render a route — it says 200
 * with the database down, the queue stalled, the scheduler dead and the last
 * backup a month old. For an ERP that bills tenants and files tax returns, that
 * is not a health check; it is a liveness check for the web server.
 *
 * Each check answers a question someone would otherwise only discover from a
 * user complaint:
 *
 *   database   — can we read? (everything depends on this)
 *   cache      — DB-backed here, so this also catches a half-broken DB
 *   queue      — are jobs piling up or failing? ETA submissions and GL sync ride the queue
 *   scheduler  — did cron run? 25 scheduled entries include billing, GL sync and BACKUPS
 *   backups    — is there a recent archive? the safeguard nobody checks until they need it
 *   storage    — can we write? uploads and PDFs die silently otherwise
 *
 * The scheduler check deliberately reads a FILE, never the database or cache.
 * Both of those are database-backed in this app, so a DB outage would otherwise
 * report "scheduler dead" as well and bury the real fault under a second,
 * wrong alarm.
 */
class Health
{
    /** Where the scheduler stamps that it ran. Not in storage/app — that is backed up. */
    public static function heartbeatPath(): string
    {
        return storage_path('framework/scheduler-heartbeat');
    }

    /** Called every minute by the scheduler. Cheap, and independent of DB/cache. */
    public static function stampHeartbeat(): void
    {
        File::ensureDirectoryExists(dirname(self::heartbeatPath()));
        File::put(self::heartbeatPath(), (string) now()->getTimestamp());
    }

    /**
     * Run every check.
     *
     * @return array{status: string, checks: array<string, array{ok: bool, detail: string}>}
     */
    public static function run(): array
    {
        $checks = [
            'database' => self::checkDatabase(),
            'cache' => self::checkCache(),
            'queue' => self::checkQueue(),
            'scheduler' => self::checkScheduler(),
            'backups' => self::checkBackups(),
            'storage' => self::checkStorage(),
        ];

        $ok = collect($checks)->every(fn (array $c): bool => $c['ok']);

        return [
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    /** @return array{ok: bool, detail: string} */
    private static function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true, 'detail' => 'reachable'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'unreachable: '.$e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private static function checkCache(): array
    {
        try {
            $key = 'health:probe';
            Cache::put($key, '1', 10);

            return Cache::get($key) === '1'
                ? ['ok' => true, 'detail' => 'read/write ok']
                : ['ok' => false, 'detail' => 'wrote a value and read back something else'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'failed: '.$e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private static function checkQueue(): array
    {
        try {
            $failed = DB::table('failed_jobs')->count();
            $pending = DB::table('jobs')->count();

            $maxFailed = (int) config('health.max_failed_jobs');
            $maxPending = (int) config('health.max_pending_jobs');

            if ($failed > $maxFailed) {
                return ['ok' => false, 'detail' => "{$failed} failed job(s) (max {$maxFailed})"];
            }

            // A large backlog means the worker is not running — the same silent
            // failure as a dead scheduler, and it stalls ETA + GL sync.
            if ($pending > $maxPending) {
                return ['ok' => false, 'detail' => "{$pending} job(s) queued (max {$maxPending}) — is the worker running?"];
            }

            return ['ok' => true, 'detail' => "{$pending} queued, {$failed} failed"];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'unreadable: '.$e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private static function checkScheduler(): array
    {
        $path = self::heartbeatPath();

        if (! File::exists($path)) {
            return ['ok' => false, 'detail' => 'never ran (no heartbeat) — is cron installed?'];
        }

        $age = now()->getTimestamp() - (int) File::get($path);
        $max = (int) config('health.max_scheduler_age_seconds');

        return $age <= $max
            ? ['ok' => true, 'detail' => "last ran {$age}s ago"]
            : ['ok' => false, 'detail' => "last ran {$age}s ago (max {$max}) — cron has stopped"];
    }

    /**
     * Backup freshness, read independently of `backup:monitor`.
     *
     * That command is itself scheduled, so a dead cron silences the backups AND
     * the alarm that would report them missing. This check runs from an HTTP
     * request instead, which is the only way the failure is visible from outside
     * the box.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function checkBackups(): array
    {
        $disks = config('backup.backup.destination.disks', []);
        $disk = is_array($disks) ? ($disks[0] ?? null) : null;

        if ($disk === null) {
            return ['ok' => false, 'detail' => 'no backup destination configured'];
        }

        try {
            $files = collect(Storage::disk($disk)->allFiles())
                ->filter(fn (string $f): bool => str_ends_with($f, '.zip'));

            if ($files->isEmpty()) {
                return ['ok' => false, 'detail' => "no archive on disk [{$disk}]"];
            }

            $newest = $files->max(fn (string $f): int => Storage::disk($disk)->lastModified($f));
            $ageHours = (int) floor((now()->getTimestamp() - $newest) / 3600);
            $max = (int) config('health.max_backup_age_hours');

            return $ageHours <= $max
                ? ['ok' => true, 'detail' => "newest archive {$ageHours}h old"]
                : ['ok' => false, 'detail' => "newest archive {$ageHours}h old (max {$max}h)"];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => "disk [{$disk}] unreadable: ".$e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private static function checkStorage(): array
    {
        try {
            $probe = 'health/probe.txt';
            Storage::disk('local')->put($probe, (string) now()->getTimestamp());
            $ok = Storage::disk('local')->exists($probe);
            Storage::disk('local')->delete($probe);

            return $ok
                ? ['ok' => true, 'detail' => 'writable']
                : ['ok' => false, 'detail' => 'wrote a file that did not appear'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'not writable: '.$e->getMessage()];
        }
    }
}
