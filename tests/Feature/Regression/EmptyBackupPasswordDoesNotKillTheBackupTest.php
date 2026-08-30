<?php

declare(strict_types=1);

/**
 * An EMPTY `BACKUP_ARCHIVE_PASSWORD` must mean "no encryption", never "no backup".
 *
 * `.env.example` ships the key present-but-empty, so a box built by copying it has
 * `env('BACKUP_ARCHIVE_PASSWORD') === ''`. Empty string is not null, so spatie's
 * `$password !== null` guard switches AES encryption on with an empty password, and
 * libzip refuses the archive at close(). The dump has already run and the zip is
 * already full by then, so the operator sees `ZipArchive::close(): Invalid argument`
 * — which reads as a corrupt file, not as a missing setting — and no archive is
 * written at all. Found on the staging box 2026-08-30, where it took out every
 * `backup:run`, `--only-db` included.
 */
it('resolves an empty archive password to null rather than an empty string', function () {
    $restore = [getenv('BACKUP_ARCHIVE_PASSWORD'), $_ENV['BACKUP_ARCHIVE_PASSWORD'] ?? null];

    try {
        putenv('BACKUP_ARCHIVE_PASSWORD=');
        $_ENV['BACKUP_ARCHIVE_PASSWORD'] = '';

        // Evaluate the config file itself — the coercion lives in that expression,
        // and reading config() would only report whatever was cached at boot.
        $config = require base_path('config/backup.php');

        expect($config['backup']['password'])->toBeNull();
    } finally {
        $restore[0] === false ? putenv('BACKUP_ARCHIVE_PASSWORD') : putenv('BACKUP_ARCHIVE_PASSWORD='.$restore[0]);
        $restore[1] === null
            ? array_key_exists('BACKUP_ARCHIVE_PASSWORD', $_ENV) && ($_ENV['BACKUP_ARCHIVE_PASSWORD'] = null)
            : $_ENV['BACKUP_ARCHIVE_PASSWORD'] = $restore[1];
    }
});

it('still passes a real password through untouched', function () {
    $restore = getenv('BACKUP_ARCHIVE_PASSWORD');

    try {
        putenv('BACKUP_ARCHIVE_PASSWORD=s3cret');
        $_ENV['BACKUP_ARCHIVE_PASSWORD'] = 's3cret';

        $config = require base_path('config/backup.php');

        expect($config['backup']['password'])->toBe('s3cret');
    } finally {
        $restore === false ? putenv('BACKUP_ARCHIVE_PASSWORD') : putenv('BACKUP_ARCHIVE_PASSWORD='.$restore);
        unset($_ENV['BACKUP_ARCHIVE_PASSWORD']);
    }
});

/**
 * Why the coercion is required at all — pinned as a CONTRACT, the way
 * FilamentActionDispatchContractTest pins upstream behaviour we depend on.
 * If a future libzip starts tolerating an empty password this goes red, and the
 * comment in config/backup.php can be revisited rather than silently outliving
 * its reason.
 */
it('confirms libzip refuses an archive encrypted with an empty password', function () {
    $path = tempnam(sys_get_temp_dir(), 'atriom-zip').'.zip';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('a.txt', 'hi');
    $zip->setEncryptionName('a.txt', ZipArchive::EM_AES_256);
    $zip->setPassword('');

    expect(@$zip->close())->toBeFalse();

    @unlink($path);
})->skip(! extension_loaded('zip'), 'ext-zip not loaded');
