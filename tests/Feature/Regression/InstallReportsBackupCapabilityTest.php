<?php

use App\Support\Health;

/**
 * The first-deploy command must say whether the box can back itself up.
 *
 * `atriom:install` used to answer that by suggesting another command — its closing bullet was
 * *"run `php artisan atriom:health`, it also checks cron, the queue worker and backups"*. On the
 * real deployment nobody did: `mysqldump` was absent, `backup:run` exited 127, and **twelve days
 * passed with no archive written at all** while the health check sat there reporting it correctly
 * to no one.
 *
 * The mechanism was never missing. What was missing is anything that forced the question at the one
 * moment somebody is certain to be looking at the output. This command already refuses to finish
 * when the database cannot post; the safeguard deciding whether that data survives a dead disk gets
 * the same standard.
 *
 * Reported, not fatal — configuring backups after installing is a legitimate order of operations,
 * and an installer that refused would just be run with the check turned off. It must simply not be
 * one bullet among four.
 */
it('warns loudly when production cannot back itself up', function () {
    // Both failure modes at once, which is the real deployment's state: no dump binary AND every
    // destination local.
    $verdict = Health::backupCapability(
        driver: 'mysql',
        dumpBinaryPath: '/nonexistent/bin',
        disks: ['backups'],
        environment: 'production',
    );

    expect($verdict['ok'])->toBeFalse()
        ->and($verdict['detail'])->toContain('mysqldump')
        ->and($verdict['detail'])->toContain('local disk');
});

it('reports each problem separately, because they are fixed in different places', function () {
    // The dump binary is a deploy-image change; BACKUP_DISKS is an env change. Running them into
    // one sentence is how half of a two-part fix gets applied and the other half forgotten.
    $verdict = Health::backupCapability(
        driver: 'mysql',
        dumpBinaryPath: '/nonexistent/bin',
        disks: ['backups'],
        environment: 'production',
    );

    expect(explode('; ', $verdict['detail']))->toHaveCount(2);
});

it('passes when a dump binary is present and one destination is off-box', function () {
    // The paired control. Without it, "warns on failure" would pass just as happily on a check
    // that always warns — and a check that always warns is one the operator learns to scroll past.
    //
    // A real executable named `mysqldump`, because the check resolves the binary by name inside the
    // configured directory; pointing at any old folder would prove nothing about that lookup.
    $dir = sys_get_temp_dir().'/atriom-wht-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/mysqldump', "#!/bin/sh\nexit 0\n");
    chmod($dir.'/mysqldump', 0o755);

    try {
        $verdict = Health::backupCapability(
            driver: 'mysql',
            dumpBinaryPath: $dir,
            disks: ['backups', 's3'],
            environment: 'production',
        );

        expect($verdict['ok'])->toBeTrue();
    } finally {
        @unlink($dir.'/mysqldump');
        @rmdir($dir);
    }
});

it('needs BOTH halves — a binary alone is not a backup', function () {
    // An off-box destination is not optional. A dump written only to the database's own machine
    // dies with it, which is the exact failure a backup exists to survive.
    $dir = sys_get_temp_dir().'/atriom-wht-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/mysqldump', "#!/bin/sh\nexit 0\n");
    chmod($dir.'/mysqldump', 0o755);

    try {
        $verdict = Health::backupCapability(
            driver: 'mysql',
            dumpBinaryPath: $dir,
            disks: ['backups'],
            environment: 'production',
        );

        expect($verdict['ok'])->toBeFalse()
            ->and($verdict['detail'])->toContain('local disk');
    } finally {
        @unlink($dir.'/mysqldump');
        @rmdir($dir);
    }
});

it('says so on the install output rather than deferring to another command', function () {
    // Driving the real command. sqlite needs no dump binary, so a test install reports OK — what
    // this pins is that the installer TALKS about backups at all, which is the whole defect.
    $this->artisan('atriom:install --force')
        ->expectsOutputToContain('Backups')
        ->assertSuccessful();
});

it('treats local and testing as not-enforced, but still says what is wrong', function () {
    // A developer box must not be nagged into ignoring this — but it must not be told everything is
    // fine either, or the message becomes new information on the day it reaches production.
    $verdict = Health::backupCapability(
        driver: 'mysql',
        dumpBinaryPath: '/nonexistent/bin',
        disks: ['backups'],
        environment: 'local',
    );

    expect($verdict['ok'])->toBeTrue()
        ->and($verdict['detail'])->toContain('not enforced')
        ->and($verdict['detail'])->toContain('mysqldump');
});
