<?php

use App\Jobs\ApplyLateFees;
use App\Support\Health;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Two health checks reported on something other than what production runs.
 *
 * **The queue check counted rows in the `jobs` and `failed_jobs` TABLES**, whatever
 * `QUEUE_CONNECTION` was set to. On the database driver that is correct; on redis or sqs those
 * tables stay empty forever, so a queue hours behind — or a worker that died last week — reported
 * "0 queued, 0 failed". A green tick that cannot go red is worse than no check, and this one is
 * what stands between a stopped worker and silently unposted ledger entries.
 *
 * **The backups check read `$disks[0]` and stopped.** The recommended go-live setting is
 * `BACKUP_DISKS="backups,s3"`, and the entire reason the second disk exists is that the first dies
 * with the machine — so the destination that actually protects the operator was the one never
 * looked at. An s3 upload failing on credentials read as healthy, indefinitely.
 *
 * And `integrations:check --mail` gave `MAIL_MAILER=log` a green tick, in every environment.
 */
it('counts the depth of the CONFIGURED queue, not every row in the jobs table', function () {
    // The discriminator, using only the database driver: the connection is pointed at the `reports`
    // queue, and there is other work sitting on `default`. `DB::table('jobs')->count()` — what this
    // used to do — says 3. Asking the driver for the configured queue says 1. On redis or sqs the
    // old answer was not merely wrong but permanently 0, which is a green tick that cannot go red.
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.queue', 'reports');
    config()->set('queue.connections.database.driver', 'database');

    Queue::connection('database')->push(new ApplyLateFees, '', 'reports');
    Queue::connection('database')->push(new ApplyLateFees, '', 'default');
    Queue::connection('database')->push(new ApplyLateFees, '', 'default');

    $check = Health::run()['checks']['queue'];

    expect(DB::table('jobs')->count())->toBe(3)
        ->and($check['detail'])->toContain('1 queued')
        ->and($check['detail'])->toContain('[database]');
});

it('does not claim an empty queue when the driver runs jobs inline', function () {
    // `sync` has no queue and no worker. Reporting a depth of 0 would imply one exists and is
    // keeping up — the same false comfort in a different shape.
    config()->set('queue.default', 'sync');

    $check = Health::run()['checks']['queue'];

    expect($check['ok'])->toBeTrue()
        ->and($check['detail'])->toContain('inline');
});

it('fails when failed jobs are not recorded anywhere', function () {
    // `QUEUE_FAILED_DRIVER=null` means a job that dies leaves no trace. Counting that as "0 failed"
    // is precisely the fail-open this check exists to stop.
    config()->set('queue.default', 'database');
    // The real shape of "nothing records failures": Laravel resolves QUEUE_FAILED_DRIVER=null to a
    // provider that accepts a failure and forgets it. Binding an actual null cannot express this —
    // `isset()` is false for null, so the container just re-resolves the real one.
    config()->set('queue.failed.driver', 'null');
    app()->forgetInstance('queue.failer');

    $check = Health::run()['checks']['queue'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('no trace');
});

it('checks EVERY backup destination, not just the first', function () {
    // The headline. One healthy local copy and a dead off-site one is not a backup — it is the
    // state you discover on the day the box is gone.
    Storage::fake('local_backups');
    Storage::fake('offsite');
    Storage::disk('local_backups')->put('atriom/2026-08-12.zip', 'x');
    // `offsite` deliberately left empty — the copy that would survive the machine is missing.

    config()->set('backup.backup.destination.disks', ['local_backups', 'offsite']);

    $check = Health::run()['checks']['backups'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('offsite');
});

it('passes when every destination has a fresh archive — the paired control', function () {
    // Without this the check could be failing on all input and the test above would still pass.
    Storage::fake('local_backups');
    Storage::fake('offsite');
    Storage::disk('local_backups')->put('atriom/2026-08-12.zip', 'x');
    Storage::disk('offsite')->put('atriom/2026-08-12.zip', 'x');

    config()->set('backup.backup.destination.disks', ['local_backups', 'offsite']);

    $check = Health::run()['checks']['backups'];

    expect($check['ok'])->toBeTrue()
        ->and($check['detail'])->toContain('local_backups')
        ->and($check['detail'])->toContain('offsite');
});

it('names an unreadable destination rather than reporting the whole check green', function () {
    Storage::fake('local_backups');
    Storage::disk('local_backups')->put('atriom/2026-08-12.zip', 'x');

    config()->set('backup.backup.destination.disks', ['local_backups', 'a-disk-that-does-not-exist']);

    $check = Health::run()['checks']['backups'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('a-disk-that-does-not-exist');
});

it('fails the mail preflight when production is writing email to a log', function () {
    // Every tenant invoice, every overdue reminder, every ledger-drift alert — into a file nobody
    // reads, while the preflight whose job is to catch that said fine.
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('mail.default', 'log');

    $this->artisan('integrations:check --mail')
        ->expectsOutputToContain('PRODUCTION')
        ->assertFailed();
});

it('accepts a log mailer outside production — the paired control', function () {
    // It is the normal local setting; failing everywhere would make the check noise.
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('mail.default', 'log');

    $this->artisan('integrations:check --mail')->assertSuccessful();
});
