<?php

declare(strict_types=1);

use App\Support\Health;
use Illuminate\Support\Facades\File;

/**
 * The scheduler heartbeat must be git-ignored, or `./deploy.sh` can never run again.
 *
 * Two correct things collide. `Health::stampHeartbeat()` writes
 * `storage/framework/scheduler-heartbeat` EVERY MINUTE — that is how a dead cron becomes
 * visible from outside the box, and it is deliberately a file rather than a cache key so
 * the check survives Redis being down. And `deploy.sh` refuses a dirty working tree,
 * because `git pull` over local edits either clobbers them or conflicts halfway through a
 * release.
 *
 * Untracked and unignored, the heartbeat makes the tree permanently dirty, so on any box
 * where the scheduler actually WORKS every deploy is refused at the first check. The
 * obvious workaround — delete the file — lasts less than sixty seconds. Note that
 * `storage/framework/.gitignore` already lists `schedule-*` for Laravel's own mutex files;
 * the heartbeat sits beside them and did not match that pattern.
 *
 * Found on the staging box 2026-08-30, on the first attempt to use `deploy.sh` after cron
 * had been installed — i.e. it could not have been found before the scheduler ran.
 */
it('git-ignores the scheduler heartbeat so a working cron cannot block the deploy', function () {
    $relative = str_replace(base_path().'/', '', Health::heartbeatPath());

    $ignored = trim(shell_exec(
        'cd '.escapeshellarg(base_path()).' && git check-ignore '.escapeshellarg($relative).' 2>/dev/null'
    ) ?? '');

    expect($ignored)->not->toBe(
        '',
        "The scheduler heartbeat [{$relative}] is not git-ignored, so every box with a working "
        .'cron has a permanently dirty tree and deploy.sh refuses to run.'
    );
})->skip(fn () => ! File::exists(base_path('.git')), 'not a git checkout');

it('still guards the deploy against a genuinely dirty tree', function () {
    // The fix must be "ignore the heartbeat", never "stop checking the tree" — the guard is
    // what stops `git pull` clobbering an edit someone made on the server mid-incident.
    expect(File::get(base_path('deploy.sh')))
        ->toContain('git status --porcelain')
        ->toContain('the working tree has local changes');
});
