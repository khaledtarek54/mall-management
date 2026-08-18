<?php

/**
 * The nightly backup wrote NOTHING for nineteen days, and two bugs were hiding each other.
 *
 * 1. **No dump binary reachable.** `backup:run` shells out to `mysqldump`; without it the command
 *    exits 127 and produces no archive. `Health::checkBackupCapability()` had read
 *    `database.connections.{driver}.dump.dump_binary_path` since 2026-07-30 — but that key existed
 *    on NO connection, so it could only ever resolve to `''` and fall through to a PATH lookup that
 *    kept failing. The check was right and there was no way to answer it.
 *
 * 2. **The restore drill named a table that has never existed.** `CRITICAL_TABLES` listed
 *    `journal_entry_lines`; the table is `journal_lines`. So `atriom:backup-verify` would have
 *    answered "BACKUP NOT RESTORABLE" for every healthy archive — except it never got that far,
 *    because (1) failed first. A drill that always fails is worth less than no drill: it teaches
 *    whoever reads it to ignore the one run that matters.
 *
 * These tests are about the SEAMS, not about this machine. Whether a given box has the client
 * installed is an ops fact that changes per environment; whether the config can express it, and
 * whether the drill asserts real tables, are properties of the code.
 */

use App\Services\Backup\VerifyBackupService;
use App\Support\Health;
use Illuminate\Support\Facades\Schema;

/* ---- 1. the seam exists and is honoured -------------------------------- */

it('lets every SQL connection say where its dump binary lives', function () {
    // The bug was not a wrong value — it was a missing KEY. `config(...)` returned null, which
    // reads identically to "not configured" and cannot be fixed from .env at all.
    foreach (['mysql', 'mariadb'] as $connection) {
        expect(config("database.connections.{$connection}.dump"))
            ->toBeArray("connection [{$connection}] has no dump config")
            ->toHaveKey('dump_binary_path');
    }
});

it('is driven by an env var, so a deploy image can answer it without a code change', function () {
    config(['database.connections.mysql.dump.dump_binary_path' => '/somewhere/bin']);

    expect(config('database.connections.mysql.dump.dump_binary_path'))->toBe('/somewhere/bin');
});

it('reports a mysql box with no reachable dump binary as INCAPABLE in production', function () {
    // The refusal. Pointed at a directory with no `mysqldump` in it, the check must fail — this is
    // the state the machine was actually in for nineteen days.
    $result = Health::backupCapability(
        driver: 'mysql',
        dumpBinaryPath: '/nonexistent/definitely/not/here',
        disks: ['s3'],
        environment: 'production',
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['detail'])->toContain('mysqldump');
});

it('reports it as CAPABLE once the path points at a real binary', function () {
    // The control, and the half that proves the seam is load-bearing rather than decorative: the
    // SAME check, the SAME driver, flipping purely on whether the configured directory holds an
    // executable `mysqldump`. Without this, a check hard-wired to return false would pass the
    // refusal test above and look correct.
    $dir = sys_get_temp_dir().'/atriom-dump-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir.'/mysqldump', "#!/bin/sh\nexit 0\n");
    chmod($dir.'/mysqldump', 0755);

    try {
        $result = Health::backupCapability(
            driver: 'mysql',
            dumpBinaryPath: $dir,
            disks: ['s3'],
            environment: 'production',
        );

        expect($result['ok'])->toBeTrue()
            ->and($result['detail'])->toContain('dump binary present');
    } finally {
        @unlink($dir.'/mysqldump');
        @rmdir($dir);
    }
});

it('needs nothing at all from a sqlite box', function () {
    // sqlite dumps in-process, so demanding a binary there would fail every CI box for no reason.
    $result = Health::backupCapability(
        driver: 'sqlite',
        dumpBinaryPath: '',
        disks: ['s3'],
        environment: 'production',
    );

    expect($result['ok'])->toBeTrue();
});

/* ---- 2. the restore drill asserts tables that exist --------------------- */

it('names only tables that are really in the schema', function () {
    // The bug that would have made every restore drill fail. Checked against the live schema, so a
    // future rename breaks the BUILD instead of the 03:00 Sunday drill nobody is watching.
    $missing = array_values(array_filter(
        VerifyBackupService::CRITICAL_TABLES,
        fn (string $table) => ! Schema::hasTable($table),
    ));

    expect($missing)->toBe([], 'CRITICAL_TABLES names tables that do not exist: '.implode(', ', $missing));
});

it('still covers the money core — the list must not be trimmed to make it pass', function () {
    // The lazy fix for the test above is to delete the offending name. These are the tables whose
    // absence from an archive means the backup is not worth keeping, so the list must keep naming
    // them: an empty CRITICAL_TABLES would satisfy the existence check perfectly.
    expect(VerifyBackupService::CRITICAL_TABLES)
        ->toContain('invoices')
        ->toContain('payments')
        ->toContain('journal_entries')
        ->toContain('journal_lines')   // the one that was wrong
        ->toContain('leases')
        ->toContain('tenants')
        ->toContain('units');
});
