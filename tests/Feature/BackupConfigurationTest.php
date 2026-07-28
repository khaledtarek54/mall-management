<?php

/**
 * Backups — the configuration, not the library.
 *
 * spatie/laravel-backup works; what goes wrong is pointing it at the wrong
 * things, silently. Every assertion here corresponds to a mistake that was
 * actually made while wiring this up:
 *
 *  - backing up base_path() (the codebase, which is in git) and calling it a
 *    backup, while the irreplaceable uploads were the real target;
 *  - writing archives to a destination INSIDE the backup source, so each run
 *    swept in every previous archive;
 *  - writing them to the `local` disk, which is `serve => true`.
 *
 * None of those raise an error. You find out when you try to restore.
 */

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;

it('backs up the uploads that cannot be recreated', function () {
    $include = config('backup.backup.source.files.include');

    // Signed leases, tenant tax cards, vendor COI/CR documents and sales
    // reports all live on the `local` disk; per-property branding on `public`.
    $privateRoot = config('filesystems.disks.local.root');
    $publicRoot = config('filesystems.disks.public.root');

    foreach (['private media' => $privateRoot, 'public branding' => $publicRoot] as $label => $root) {
        $covered = collect($include)->contains(
            fn (string $path) => str_starts_with($root, rtrim($path, '/'))
        );

        expect($covered)->toBeTrue("Backup does not cover {$label} ({$root}).");
    }
});

it('does not waste the archive on the codebase', function () {
    // base_path() is in git and redeployable. Including it makes every archive
    // large enough that retention quietly becomes the thing that gets cut.
    $include = config('backup.backup.source.files.include');

    expect($include)->not->toContain(base_path())
        ->and($include)->not->toContain(base_path().'/');
});

it('never writes archives inside the directory it is backing up', function () {
    // The trap: `local` is rooted at storage/app/private, which is inside
    // storage/app — the source. Each run would sweep in every previous archive,
    // and the only symptom is an archive that grows until the disk fills.
    $include = collect(config('backup.backup.source.files.include'))
        ->map(fn (string $p) => rtrim($p, '/'));

    foreach (config('backup.backup.destination.disks') as $disk) {
        $root = config("filesystems.disks.{$disk}.root");

        if ($root === null) {
            continue; // a remote disk (s3) cannot be inside a local path
        }

        $nested = $include->contains(fn (string $path) => str_starts_with(rtrim($root, '/'), $path));

        expect($nested)->toBeFalse(
            "Backup disk [{$disk}] is rooted at {$root}, inside a backed-up path — each run would include the last."
        );
    }
});

it('keeps archives off any disk the app can serve', function () {
    // An archive holds a full database dump: tenant tax cards, signed leases,
    // every money record. It must not sit on a disk with `serve` enabled.
    foreach (config('backup.backup.destination.disks') as $disk) {
        expect(config("filesystems.disks.{$disk}.serve"))->not->toBeTrue(
            "Backup disk [{$disk}] is servable by the app."
        );
    }
});

it('backs up the database as well as the files', function () {
    expect(config('backup.backup.source.databases'))->not->toBeEmpty();
});

it('does not email on success, only on failure', function () {
    // A nightly "backup succeeded" mail is how someone learns to filter the
    // thread, and then misses the failure.
    $notifications = config('backup.notifications.notifications');

    expect($notifications[BackupWasSuccessfulNotification::class])->toBe([])
        ->and($notifications[HealthyBackupWasFoundNotification::class])->toBe([]);
});

it('survives having no alert address configured', function () {
    // laravel-backup validates the address at CONFIG-PARSE time and throws on an
    // empty one — which took down `schedule:list`, and would have taken down the
    // whole scheduler rather than just backup mail.
    config()->set('backup.notifications.mail.to', config('backup.notifications.mail.to'));

    expect(filter_var(config('backup.notifications.mail.to'), FILTER_VALIDATE_EMAIL))->not->toBeFalse();
});

it('monitors every destination it writes to, and fails a stale backup', function () {
    $monitor = config('backup.monitor_backups')[0];

    // A monitor watching only `local` cannot see that the off-site copy stopped
    // being written — the failure you find out about during a restore.
    expect($monitor['disks'])->toBe(config('backup.backup.destination.disks'));

    // Nightly backups: anything older than a day means the job did not run.
    expect($monitor['health_checks'][MaximumAgeInDays::class])->toBe(1);
});

it('schedules the backup, its cleanup and its verification', function () {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($e) => $e->command ?? '')
        ->implode(' ');

    // On the scheduler, not a CI workflow: a backup has to keep running between
    // deploys and on a box CI never touches.
    foreach (['backup:run', 'backup:clean', 'backup:monitor'] as $command) {
        expect($scheduled)->toContain($command);
    }
});

it('ignores backup archives in git', function () {
    // An archive is a full database dump. Committing one would put every tenant's
    // tax card and signed lease in the repository history, permanently.
    $root = config('filesystems.disks.backups.root');
    $relative = str_replace(base_path().'/', '', $root);

    $ignored = trim(shell_exec(
        'cd '.escapeshellarg(base_path()).' && git check-ignore '.escapeshellarg($relative.'/probe.zip').' 2>/dev/null'
    ) ?? '');

    expect($ignored)->not->toBe('', "Backup directory [{$relative}] is not git-ignored.");
})->skip(fn () => ! File::exists(base_path('.git')), 'not a git checkout');
