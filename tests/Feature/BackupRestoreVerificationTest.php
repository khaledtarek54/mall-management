<?php

/*
|--------------------------------------------------------------------------
| The backup can actually be restored
|--------------------------------------------------------------------------
| BackupConfigurationTest checks that backups point at the right things. Its docblock ends: "None
| of those raise an error. You find out when you try to restore." This is the part that tries.
|
| `backup:run` writes an archive and `backup:monitor` fails when the newest one goes stale, but
| nothing had ever OPENED one. Every way a backup dies looks identical to a healthy one from the
| outside — and one of them was live on the machine this was written on: with no `mysqldump` binary
| on PATH, `backup:run` exits 127, so the nightly job had been producing nothing at all.
|
| The restore itself needs a MySQL server, so that case is skipped on the sqlite suite and says so
| rather than passing quietly. Everything that can be tested without one — the safety guard and
| every archive failure mode — is tested unconditionally, because those are the failures that
| actually happen.
*/

use App\Services\Backup\BackupArchive;
use App\Services\Backup\VerifyBackupService;
use Illuminate\Support\Facades\File;

/** A .zip shaped like a spatie backup: db-dumps/<name>.sql plus a file. */
function makeArchive(string $name, ?string $sql, ?string $password = null): string
{
    $path = storage_path('app/test-archives/'.$name.'.zip');
    File::ensureDirectoryExists(dirname($path));
    File::delete($path);

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('storage/app/private/media/lease.pdf', 'a signed lease');

    if ($sql !== null) {
        $zip->addFromString('db-dumps/mysql-atriom.sql', $sql);

        if ($password !== null) {
            $zip->setPassword($password);
            $zip->setEncryptionName('db-dumps/mysql-atriom.sql', ZipArchive::EM_AES_256);
        }
    }

    $zip->close();

    return $path;
}

afterEach(fn () => File::deleteDirectory(storage_path('app/test-archives')));

/* ---- the safety guard: this tool can DROP DATABASE ------------------------ */

it('refuses to use the application database as the scratch database', function () {
    // The one that turns a verification run into the outage it exists to prevent.
    $appDatabase = config('database.connections.'.config('database.default').'.database');

    expect(fn () => app(VerifyBackupService::class)->verify($appDatabase))
        ->toThrow(RuntimeException::class);
})->skip(
    fn (): bool => config('database.connections.'.config('database.default').'.driver') !== 'mysql',
    'guard runs after the mysql-only check'
);

it('refuses an empty or unsafe scratch database name', function () {
    $service = new VerifyBackupService;
    $guard = new ReflectionMethod($service, 'assertScratchIsSafe');
    $guard->setAccessible(true);

    // Empty would make `DROP DATABASE ``` ambiguous; the rest are injection shapes.
    foreach (['', '   ', 'foo`; DROP DATABASE `atriom', 'foo bar', 'foo-bar'] as $unsafe) {
        expect(fn () => $guard->invoke($service, $unsafe, 'atriom'))
            ->toThrow(RuntimeException::class, 'Refusing to verify');
    }

    // …and a legitimate name passes.
    expect($guard->invoke($service, 'atriom__restore_check', 'atriom'))->toBeNull();
});

it('refuses an empty scratch name through the real entry point, not just the guard', function () {
    // The guard tests above call assertScratchIsSafe() directly. verify() reached it through
    // `$scratchDatabase ?: $default`, and '' is falsy — so an empty name was silently swapped for
    // the default and the guard never ran. The direct-call tests passed over a path production
    // did not take. Drive the real method.
    expect(fn () => app(VerifyBackupService::class)->verify(''))
        ->toThrow(RuntimeException::class, 'empty');

    expect(fn () => app(VerifyBackupService::class)->verify('   '))
        ->toThrow(RuntimeException::class, 'empty');
})->skip(
    fn (): bool => config('database.connections.'.config('database.default').'.driver') !== 'mysql',
    'verify() returns early off mysql, before the guard'
);

it('refuses the application database whatever its casing', function () {
    $service = new VerifyBackupService;
    $guard = new ReflectionMethod($service, 'assertScratchIsSafe');
    $guard->setAccessible(true);

    // MySQL table-name casing is platform-dependent; a case-sensitive compare would let
    // `ATRIOM` through on a case-insensitive server and drop the real database.
    expect(fn () => $guard->invoke($service, 'ATRIOM', 'atriom'))
        ->toThrow(RuntimeException::class, 'APPLICATION');
});

/* ---- the archive failure modes -------------------------------------------- */

it('reports an archive that contains no database dump', function () {
    // Live on the machine this was written on: no mysqldump binary, so backup:run exits 127 and
    // any archive left behind holds the uploads and no database. `backup:monitor` sees a recent
    // file and calls it healthy.
    $archive = makeArchive('files-only', null);

    expect(fn () => (new BackupArchive($archive))->databaseDump())
        ->toThrow(RuntimeException::class, 'no database dump');
});

it('reports an archive that will not open', function () {
    $path = storage_path('app/test-archives/corrupt.zip');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'this is not a zip file');

    expect(fn () => (new BackupArchive($path))->databaseDump())
        ->toThrow(RuntimeException::class, 'will not open');
});

it('reports a dump it cannot decrypt', function () {
    // The genuinely undetectable one: BACKUP_ARCHIVE_PASSWORD rotated, or never recorded. The
    // archive is present, the right size, and worthless.
    $archive = makeArchive('encrypted', "CREATE TABLE invoices (id INT);\n", password: 'the-real-password');

    expect(fn () => (new BackupArchive($archive))->databaseDump('the-wrong-password'))
        ->toThrow(RuntimeException::class);
});

it('reads a dump that is encrypted with the right password', function () {
    $sql = "CREATE TABLE invoices (id INT);\n";
    $archive = makeArchive('encrypted-ok', $sql, password: 'the-real-password');

    expect((new BackupArchive($archive))->databaseDump('the-real-password'))->toBe($sql);
});

it('reads a plain dump', function () {
    $sql = "CREATE TABLE invoices (id INT);\nINSERT INTO invoices VALUES (1);\n";

    expect((new BackupArchive(makeArchive('plain', $sql)))->databaseDump())->toBe($sql);
});

/* ---- the statement splitter ------------------------------------------------ */

it('does not split an INSERT on a semicolon inside a string', function () {
    // A `;` in an address or a description is ordinary. Splitting there would tear an INSERT in
    // half and report a healthy backup as unrestorable — a false alarm is how a real alarm gets
    // ignored.
    $service = new VerifyBackupService;
    $ends = new ReflectionMethod($service, 'endsStatement');
    $ends->setAccessible(true);

    expect($ends->invoke($service, "INSERT INTO t VALUES ('a; b'"))->toBeFalse()
        ->and($ends->invoke($service, "INSERT INTO t VALUES ('a; b');"))->toBeTrue()
        ->and($ends->invoke($service, "INSERT INTO t VALUES ('it\\'s; here');"))->toBeTrue()
        ->and($ends->invoke($service, 'CREATE TABLE `we;ird` (id INT);'))->toBeTrue()
        ->and($ends->invoke($service, 'CREATE TABLE t (id INT)'))->toBeFalse();
});

/* ---- the real thing -------------------------------------------------------- */

it('restores a real dump into a scratch database and reports the tables', function () {
    // The end-to-end drill. Needs a MySQL server, so it is skipped (loudly) on the sqlite suite.
    $tables = collect(VerifyBackupService::CRITICAL_TABLES)
        ->map(fn (string $t): string => "CREATE TABLE `{$t}` (id INT PRIMARY KEY);")
        ->implode("\n");

    $sql = "-- a comment\n{$tables}\nINSERT INTO `invoices` VALUES (1);\nINSERT INTO `invoices` VALUES (2);\n";

    makeArchive('restorable', $sql);

    config()->set('backup.backup.name', 'test-archives');
    config()->set('backup.backup.destination.disks', ['local-test-archives']);
    config()->set('filesystems.disks.local-test-archives', [
        'driver' => 'local',
        'root' => storage_path('app'),
    ]);

    $result = app(VerifyBackupService::class)->verify();

    expect($result['ok'])->toBeTrue($result['reason'] ?? '')
        ->and($result['tables'])->toBe(count(VerifyBackupService::CRITICAL_TABLES))
        ->and($result['rows']['invoices'])->toBe(2);
})->skip(
    fn (): bool => config('database.connections.'.config('database.default').'.driver') !== 'mysql',
    'restore drill needs a MySQL server (suite runs on sqlite)'
);

it('says it was skipped rather than passing quietly on an unsupported driver', function () {
    // A verification that silently checks nothing is the exact failure this replaces.
    $result = app(VerifyBackupService::class)->verify();

    expect($result['skipped'])->toBeTrue()
        ->and($result['ok'])->toBeFalse()
        ->and($result['reason'])->toContain('mysql only');
})->skip(
    fn (): bool => config('database.connections.'.config('database.default').'.driver') === 'mysql',
    'only meaningful off mysql'
);
