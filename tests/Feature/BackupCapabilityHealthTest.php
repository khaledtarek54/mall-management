<?php

/*
|--------------------------------------------------------------------------
| Can this machine take a backup at all, and would the copy survive it?
|--------------------------------------------------------------------------
| The existing `backups` health check reads the newest archive's AGE, so it only speaks 24 hours
| after things break — and only if an archive was ever written at all. Two conditions are knowable
| the moment the app boots:
|
|   1. no dump binary: `backup:run` shells out to mysqldump, and without it exits 127 and writes
|      nothing. Found live on this project 2026-07-30 — the nightly job had been failing since the
|      day it was scheduled;
|   2. every destination is a local disk: a copy on the same machine as the database dies with the
|      machine, which is the failure a backup exists to survive.
|
| Both fail the health check in production and neither does locally, where a developer has no
| off-site disk and no reason to install the client. A check that cries wolf in development is a
| check that gets ignored in production.
*/

use App\Support\Health;

/** A local disk registered for the test to point BACKUP_DISKS at. */
function localDisk(string $name = 'backups'): string
{
    config()->set("filesystems.disks.{$name}", ['driver' => 'local', 'root' => storage_path('backups')]);

    return $name;
}

/** An off-box destination. */
function offsiteDisk(string $name = 'offsite'): string
{
    config()->set("filesystems.disks.{$name}", ['driver' => 's3']);

    return $name;
}

/** A directory that certainly contains no mysqldump, so the check does not depend on this machine. */
function noBinaryDir(): string
{
    return storage_path('app');
}

it('passes when a dump binary is reachable and a destination leaves the machine', function () {
    // sqlite dumps in-process, so there is no external binary to find.
    $result = Health::backupCapability('sqlite', '', [offsiteDisk()], 'production');

    expect($result['ok'])->toBeTrue($result['detail']);
});

it('fails in production when every backup destination is a local disk', function () {
    // The shipped default: BACKUP_DISKS=backups, which is storage_path('backups') — the same box
    // as the database it protects.
    $result = Health::backupCapability('sqlite', '', [localDisk()], 'production');

    expect($result['ok'])->toBeFalse()
        ->and($result['detail'])->toContain('dies with the machine');
});

it('fails in production when no backup destination is configured at all', function () {
    expect(Health::backupCapability('sqlite', '', [], 'production')['ok'])->toBeFalse();
});

it('fails in production when the dump binary is missing', function () {
    // Exactly the live failure: mysql configured, no mysqldump reachable.
    $result = Health::backupCapability('mysql', noBinaryDir(), [offsiteDisk()], 'production');

    expect($result['ok'])->toBeFalse()
        ->and($result['detail'])->toContain('mysqldump')
        ->and($result['detail'])->toContain('127');
});

it('reports both problems at once rather than only the first', function () {
    // An operator fixing one and re-running should not discover the second on the next pass.
    $detail = Health::backupCapability('mysql', noBinaryDir(), [localDisk()], 'production')['detail'];

    expect($detail)->toContain('mysqldump')
        ->and($detail)->toContain('dies with the machine');
});

it('does not fail the build on a developer machine, but still says what is wrong', function () {
    // Silence here would be the same mistake in the other direction: the developer never learns
    // that the configuration they are about to deploy cannot back anything up.
    $result = Health::backupCapability('mysql', noBinaryDir(), [localDisk()], 'local');

    expect($result['ok'])->toBeTrue('local must not fail the health check')
        ->and($result['detail'])->toContain('not enforced')
        ->and($result['detail'])->toContain('mysqldump');
});

it('accepts a dump binary at an explicitly configured path', function () {
    // spatie honours dump_binary_path; a deployment that ships the client somewhere non-standard
    // must not be reported as broken.
    $dir = storage_path('app/fake-bin');
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/mysqldump', "#!/bin/sh\nexit 0\n");
    chmod($dir.'/mysqldump', 0755);

    $result = Health::backupCapability('mysql', $dir, [offsiteDisk()], 'production');

    unlink($dir.'/mysqldump');
    rmdir($dir);

    expect($result['ok'])->toBeTrue($result['detail']);
});

it('is wired into the health report', function () {
    // A check nobody runs is not a check.
    expect(array_keys(Health::run()['checks']))->toContain('backup_capability');
});
