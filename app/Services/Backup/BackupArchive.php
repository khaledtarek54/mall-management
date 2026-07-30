<?php

namespace App\Services\Backup;

use RuntimeException;
use ZipArchive;

/**
 * Reading the inside of a backup archive — the part that fails silently.
 *
 * Split from VerifyBackupService so it can be tested without a MySQL server: every failure below
 * is one that leaves a perfectly ordinary-looking .zip sitting on disk, and each needs its own
 * case.
 *
 *   - the archive will not open at all (truncated write, disk filled mid-run);
 *   - it opens but the dump will not decrypt, because BACKUP_ARCHIVE_PASSWORD is not the password
 *     it was written with — the one that is genuinely undetectable until a restore;
 *   - it opens and decrypts but holds no `db-dumps/` entry, because the dump step failed and the
 *     files step succeeded. Observed on this project: with no `mysqldump` binary on PATH,
 *     `backup:run` exits 127 and any archive it leaves behind carries the uploads and no database.
 */
class BackupArchive
{
    public function __construct(private string $path) {}

    /**
     * The SQL text of the database dump inside this archive.
     *
     * @throws RuntimeException with a message written for whoever is reading it at 3am
     */
    public function databaseDump(?string $password = null): string
    {
        $zip = new ZipArchive;
        $opened = $zip->open($this->path);

        if ($opened !== true) {
            throw new RuntimeException(
                "archive will not open (ZipArchive error {$opened}) — it is truncated or corrupt: {$this->path}"
            );
        }

        if ($password !== null && $password !== '') {
            $zip->setPassword($password);
        }

        $entry = $this->findDumpEntry($zip);

        if ($entry === null) {
            $names = [];
            for ($i = 0; $i < min($zip->numFiles, 5); $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();

            throw new RuntimeException(
                'archive contains no database dump — no db-dumps/ entry. The files were backed up '
                .'and the database was not (check that mysqldump is installed on the box running '
                .'backup:run). Archive holds: '.(implode(', ', $names) ?: '(nothing)')
            );
        }

        $contents = $zip->getFromName($entry);
        $zip->close();

        if ($contents === false || $contents === '') {
            throw new RuntimeException(
                "could not read {$entry} — wrong BACKUP_ARCHIVE_PASSWORD, or the dump is truncated"
            );
        }

        if (str_ends_with($entry, '.gz')) {
            $decoded = @gzdecode($contents);

            if ($decoded === false) {
                throw new RuntimeException("{$entry} is not valid gzip — the dump is corrupt");
            }

            $contents = $decoded;
        }

        return $contents;
    }

    private function findDumpEntry(ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $candidate = (string) $zip->getNameIndex($i);

            if (str_starts_with($candidate, 'db-dumps/') && ! str_ends_with($candidate, '/')) {
                return $candidate;
            }
        }

        return null;
    }
}
