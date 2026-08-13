<?php

namespace App\Services\Backup;

use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Restore the newest backup into a scratch database and check that it is real.
 *
 * **Why this exists.** `backup:run` writes an archive nightly and `backup:monitor` fails when the
 * newest one goes stale — but between them nothing has ever *opened* one. Every failure that
 * matters looks identical to success from the outside: an archive encrypted with a password nobody
 * kept, a dump truncated because the disk filled mid-write, a `mysqldump` binary that vanished from
 * the deploy image so the zip holds the uploads and no database at all. `BackupConfigurationTest`
 * says it plainly — "None of those raise an error. You find out when you try to restore."
 *
 * So this tries to restore, on a schedule, and fails loudly while it is still a drill.
 *
 * **What it proves, in order of what actually goes wrong:**
 *   1. the archive opens — and decrypts with the password this deployment is configured with;
 *   2. it contains a database dump, not just files;
 *   3. that dump replays into a real database without erroring;
 *   4. the tables the business cannot lose are present in the result.
 *
 * **What it deliberately does not prove.** That the *data* is correct — only that the archive is
 * structurally restorable. A restore drill that asserted business invariants would fail for reasons
 * that have nothing to do with the backup.
 *
 * **The safety rule.** The scratch database name is checked against the application's own before
 * anything is created or dropped, and the check is repeated immediately before the DROP. A restore
 * tool that can be pointed at production is worse than no restore tool: it turns a verification run
 * into the outage it was meant to prevent.
 */
class VerifyBackupService
{
    /** Tables whose absence means the archive is not worth keeping. */
    public const CRITICAL_TABLES = [
        'invoices',
        'payments',
        'journal_entries',
        'journal_entry_lines',
        'leases',
        'tenants',
        'units',
    ];

    /**
     * @return array{
     *     ok: bool, reason: ?string, archive: ?string, archive_age_hours: ?float,
     *     archive_bytes: ?int, tables: int, rows: array<string, int>, scratch: ?string, skipped: bool
     * }
     */
    public function verify(?string $scratchDatabase = null, bool $keep = false): array
    {
        $result = [
            'ok' => false, 'reason' => null, 'archive' => null, 'archive_age_hours' => null,
            'archive_bytes' => null, 'tables' => 0, 'rows' => [], 'scratch' => null, 'skipped' => false,
        ];

        $connection = config('database.default');

        // Only MySQL is restorable this way. Say so rather than reporting a false green — a
        // verification that silently checks nothing is the exact failure mode this replaces.
        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            return [...$result, 'skipped' => true, 'reason' => "restore verification supports mysql only (driver: ".config("database.connections.{$connection}.driver").')'];
        }

        $appDatabase = (string) config("database.connections.{$connection}.database");
        // `!== null`, not `?:` — an EMPTY string is a caller who asked for something specific and
        // got it wrong, and must be refused. `?:` treated it as "not provided" and silently
        // substituted the default, so the empty-name guard below was unreachable from here: the
        // unit test covering it passed only because it calls the guard directly.
        $scratch = $scratchDatabase !== null ? $scratchDatabase : $appDatabase.'__restore_check';

        $this->assertScratchIsSafe($scratch, $appDatabase);
        $result['scratch'] = $scratch;

        $archive = $this->newestArchive();

        if ($archive === null) {
            return [...$result, 'reason' => 'no backup archive found on any destination disk'];
        }

        $result['archive'] = $archive['path'];
        $result['archive_bytes'] = $archive['bytes'];
        $result['archive_age_hours'] = round((CarbonImmutable::now()->getTimestamp() - $archive['timestamp']) / 3600, 1);

        $workspace = storage_path('app/backup-verify-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($workspace);

        try {
            $dump = $this->extractDump($this->localCopyOf($archive, $workspace), $workspace);
            $this->createScratch($connection, $scratch);
            $statements = $this->replay($connection, $scratch, $dump);

            $tables = $this->tablesIn($connection, $scratch);
            $result['tables'] = count($tables);

            $missing = array_values(array_diff(self::CRITICAL_TABLES, $tables));

            if ($missing !== []) {
                return [...$result, 'reason' => 'restored, but these critical tables are missing: '.implode(', ', $missing)];
            }

            $result['rows'] = $this->rowCounts($connection, $scratch, self::CRITICAL_TABLES);
            $result['ok'] = true;
            $result['reason'] = "replayed {$statements} statements";

            return $result;
        } catch (RuntimeException $e) {
            return [...$result, 'reason' => $e->getMessage()];
        } finally {
            if (! $keep) {
                $this->dropScratch($connection, $scratch, $appDatabase);
            }

            File::deleteDirectory($workspace);
        }
    }

    /**
     * The guard that makes this tool safe to schedule.
     *
     * Checked before creation AND again before the drop — a caller that passes the app's own
     * database name, or an empty one that would make `DROP DATABASE ``” ` ambiguous, must be
     * refused rather than trusted.
     */
    private function assertScratchIsSafe(string $scratch, string $appDatabase): void
    {
        if (trim($scratch) === '') {
            throw new RuntimeException('Refusing to verify: the scratch database name is empty.');
        }

        if (strcasecmp($scratch, $appDatabase) === 0) {
            throw new RuntimeException(
                "Refusing to verify: the scratch database ({$scratch}) is the APPLICATION's own database. "
                .'This would drop production data.'
            );
        }

        if (! preg_match('/\A[A-Za-z0-9_]+\z/', $scratch)) {
            throw new RuntimeException("Refusing to verify: unsafe scratch database name ({$scratch}).");
        }
    }

    /** The newest archive across every configured destination disk. */
    private function newestArchive(): ?array
    {
        $newest = null;
        $name = (string) config('backup.backup.name');

        foreach ((array) config('backup.backup.destination.disks') as $disk) {
            $storage = Storage::disk($disk);

            foreach ($storage->files($name) as $file) {
                if (! str_ends_with($file, '.zip')) {
                    continue;
                }

                $timestamp = $storage->lastModified($file);

                if ($newest === null || $timestamp > $newest['timestamp']) {
                    $newest = [
                        'path' => $disk.':'.$file,
                        // NOT `$storage->path($file)` — see localCopyOf(). On a remote disk that
                        // returns a key, not a readable file, so the archive is fetched instead.
                        'disk' => $disk,
                        'file' => $file,
                        'timestamp' => $timestamp,
                        'bytes' => $storage->size($file),
                    ];
                }
            }
        }

        return $newest;
    }

    /**
     * A path on THIS machine's filesystem for the archive, fetching it first when it is not local.
     *
     * **Why this is not `Storage::disk($d)->path($file)`.** That is a local-driver method: on S3 it
     * returns the object KEY, so the reader below opened a path that does not exist and the drill
     * failed. The recommended production setting is `BACKUP_DISKS="backups,s3"` precisely so a copy
     * survives the machine — which means the off-site copy was the ONE the drill could never
     * verify, and the newest archive is usually the remote one, so this was not a rare branch.
     *
     * Streamed rather than read into memory: a real mall's dump is not something to hold in a PHP
     * string, and this runs on a box sized for the app rather than for restores.
     *
     * @param  array{disk: string, file: string, path: string}  $archive
     */
    private function localCopyOf(array $archive, string $workspace): string
    {
        $storage = Storage::disk($archive['disk']);

        if (config("filesystems.disks.{$archive['disk']}.driver") === 'local') {
            return $storage->path($archive['file']);
        }

        $destination = $workspace.'/'.basename($archive['file']);
        $source = $storage->readStream($archive['file']);

        if ($source === null || $source === false) {
            throw new RuntimeException("could not read {$archive['path']} from the {$archive['disk']} disk");
        }

        $target = fopen($destination, 'w');

        if ($target === false) {
            fclose($source);

            throw new RuntimeException('could not open a local workspace file for the archive');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        return $destination;
    }

    /** Write the archive's DB dump into $workspace, via the dedicated reader. */
    private function extractDump(string $archivePath, string $workspace): string
    {
        $sql = (new BackupArchive($archivePath))->databaseDump(
            (string) config('backup.backup.password')
        );

        $path = $workspace.'/dump.sql';
        File::put($path, $sql);

        return $path;
    }

    /**
     * Make an empty scratch schema — creating it, or emptying a pre-created one.
     *
     * **`CREATE DATABASE` is not a privilege the production user necessarily has.** The topology in
     * INFRASTRUCTURE.md is Aiven managed MySQL, where the application account is scoped to its own
     * schema and cannot create another. So the drill this project schedules weekly would have
     * failed on the real box with a raw SQL privilege error — on the one tool whose entire purpose
     * is to prove the backups are restorable.
     *
     * The fallback is to REUSE a scratch schema someone created once by hand, emptying it instead.
     * That is a normal way to run this on managed MySQL: create `<app>__restore_check`, grant the
     * app user rights on it, and the weekly drill works from then on with no elevated privilege.
     *
     * `assertScratchIsSafe()` has already refused the application's own database by the time
     * anything here runs, which is what makes "empty every table in it" safe to do.
     */
    private function createScratch(string $connection, string $scratch): void
    {
        try {
            DB::connection($connection)->statement("DROP DATABASE IF EXISTS `{$scratch}`");
            DB::connection($connection)->statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            if (! $this->schemaExists($connection, $scratch)) {
                throw new RuntimeException(
                    "cannot create the scratch database `{$scratch}` ({$e->getMessage()}). "
                    ."On managed MySQL the app user often may not CREATE DATABASE — create `{$scratch}` "
                    .'once by hand, grant this user rights on it, and re-run: the drill will reuse it.'
                );
            }

            OpsLog::warning('backup.verify.reusing_scratch', ['scratch' => $scratch, 'error' => $e->getMessage()]);

            $this->emptySchema($connection, $scratch);
        }

        // A runtime clone of the app connection pointed at the scratch schema, so the replay
        // cannot touch the real one even by accident.
        Config::set('database.connections.backup_verify', array_merge(
            config("database.connections.{$connection}"),
            ['database' => $scratch]
        ));

        DB::purge('backup_verify');
    }

    /** Replay the dump statement by statement. Returns how many were executed. */
    private function replay(string $connection, string $scratch, string $dumpPath): int
    {
        $pdo = DB::connection('backup_verify')->getPdo();
        $handle = fopen($dumpPath, 'r');

        if ($handle === false) {
            throw new RuntimeException('could not read the extracted dump');
        }

        $statement = '';
        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = ltrim($line);

                // mysqldump comments — but NOT `/*!40101 ... */;` executable comments, which carry
                // charset and FK settings the restore needs.
                if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                $statement .= $line;

                if (! $this->endsStatement($statement)) {
                    continue;
                }

                $sql = trim($statement);
                $statement = '';

                if ($sql === '' || $sql === ';') {
                    continue;
                }

                try {
                    $pdo->exec($sql);
                    $count++;
                } catch (\PDOException $e) {
                    throw new RuntimeException(
                        'the dump does not replay — '.$e->getMessage().' (statement '.($count + 1).')'
                    );
                }
            }
        } finally {
            fclose($handle);
        }

        if ($count === 0) {
            throw new RuntimeException('the dump contained no executable statements — it is empty or truncated');
        }

        return $count;
    }

    /**
     * Does the buffer end a statement?
     *
     * Tracks quoting so a `;` inside a string value — very common in address and description
     * columns — does not split an INSERT in half and turn a healthy backup into a false alarm.
     */
    private function endsStatement(string $buffer): bool
    {
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $length = strlen($buffer);

        for ($i = 0; $i < $length; $i++) {
            $char = $buffer[$i];

            if ($char === '\\') {
                $i++;   // escaped character — skip whatever follows

                continue;
            }

            if ($char === "'" && ! $inDouble && ! $inBacktick) {
                $inSingle = ! $inSingle;
            } elseif ($char === '"' && ! $inSingle && ! $inBacktick) {
                $inDouble = ! $inDouble;
            } elseif ($char === '`' && ! $inSingle && ! $inDouble) {
                $inBacktick = ! $inBacktick;
            }
        }

        return ! $inSingle && ! $inDouble && ! $inBacktick && str_ends_with(rtrim($buffer), ';');
    }

    /** @return array<int, string> */
    private function tablesIn(string $connection, string $scratch): array
    {
        return array_map(
            fn (object $row): string => (string) array_values((array) $row)[0],
            DB::connection($connection)->select(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = ?',
                [$scratch]
            )
        );
    }

    /** @return array<string, int> */
    private function rowCounts(string $connection, string $scratch, array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::connection('backup_verify')->table($table)->count();
        }

        return $counts;
    }

    private function dropScratch(string $connection, string $scratch, string $appDatabase): void
    {
        // Re-checked here, not just at the top: everything between then and now is the part that
        // could have changed the name.
        $this->assertScratchIsSafe($scratch, $appDatabase);

        try {
            DB::purge('backup_verify');
            DB::connection($connection)->statement("DROP DATABASE IF EXISTS `{$scratch}`");
        } catch (\Throwable $e) {
            // Same privilege story as createScratch(): if the schema cannot be dropped, empty it,
            // so a reused scratch is not left holding a full copy of production between runs.
            try {
                $this->emptySchema($connection, $scratch);
                OpsLog::warning('backup.verify.scratch_emptied_not_dropped', ['scratch' => $scratch, 'error' => $e->getMessage()]);
            } catch (\Throwable $inner) {
                OpsLog::warning('backup.verify.scratch_not_dropped', ['scratch' => $scratch, 'error' => $inner->getMessage()]);
            }
        }
    }

    private function schemaExists(string $connection, string $schema): bool
    {
        return DB::connection($connection)->select(
            'SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?',
            [$schema]
        ) !== [];
    }

    /**
     * Drop every table in a schema, for the managed-MySQL path where the schema itself is not ours
     * to drop. Foreign keys are ignored for the duration — the dump recreates them, and dropping in
     * dependency order would be a second, weaker copy of what `DROP DATABASE` does for free.
     */
    private function emptySchema(string $connection, string $schema): void
    {
        $tables = $this->tablesIn($connection, $schema);

        if ($tables === []) {
            return;
        }

        $db = DB::connection($connection);
        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                $db->statement("DROP TABLE IF EXISTS `{$schema}`.`{$table}`");
            }
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
