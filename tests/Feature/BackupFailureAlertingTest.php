<?php

/*
|--------------------------------------------------------------------------
| A failed backup is never silent
|--------------------------------------------------------------------------
| spatie routes BackupHasFailedNotification through config/backup.php's notification channels,
| and that list is built as `$backupAlertEmail ? ['mail'] : []`. With BACKUP_ALERT_EMAIL unset —
| the shipped default — the failure notification goes to NO CHANNEL AT ALL.
|
| That was live: with no `mysqldump` binary on the box, `backup:run` exits 127 and had been
| producing nothing since the schedule was added, while `/health` went unpolled, no mail channel
| was configured, and nobody reads scheduler exit codes. Three detectors, all inert.
|
| The assertions below deliberately run with the alert email UNSET, because that is the
| configuration under which the silence happened. A test that configured a channel first would
| prove the opposite of what matters.
*/

use App\Listeners\LogBackupFailures;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

beforeEach(function () {
    // The configuration the silence happened under: every notification present but routed to NO
    // channel, which is exactly what `$backupAlertEmail ? ['mail'] : []` produces when
    // BACKUP_ALERT_EMAIL is unset. Emptying the channels rather than replacing the map — spatie
    // looks every notification class up by key, so dropping keys breaks it for unrelated reasons.
    config()->set('backup.notifications.notifications', array_map(
        fn (): array => [],
        config('backup.notifications.notifications', [])
    ));
});

/**
 * Capture what OpsLog writes, preserving the LEVEL.
 *
 * OpsLog::write() does `Log::channel('ops')->{$level}($event, $context)`, so the level is the
 * method name. A first version of this helper recorded every call as 'error', which made the
 * "success must not raise an error" assertion fail on an info-level line — the harness lying,
 * not the listener.
 */
function captureOps(Closure $work): array
{
    $records = [];

    $channel = Mockery::mock();

    foreach (['info', 'warning', 'error', 'debug', 'critical'] as $level) {
        $channel->shouldReceive($level)->andReturnUsing(
            function ($message, $context = []) use (&$records, $level) {
                $records[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        );
    }

    Log::shouldReceive('channel')->with('ops')->andReturn($channel);
    Log::shouldReceive('channel')->andReturn($channel);

    $work();

    return $records;
}

it('subscribes to the backup failure events', function () {
    // The registration itself — a listener nobody wired up is the same silence with extra steps.
    foreach ([BackupHasFailed::class, CleanupHasFailed::class, UnhealthyBackupWasFound::class] as $event) {
        expect(Event::hasListeners($event))->toBeTrue("nothing is listening for {$event}");
    }
});

it('logs a failed backup at error level even with no alert channel configured', function () {
    $records = captureOps(fn () => Event::dispatch(
        new BackupHasFailed(new Exception('sh: mysqldump: command not found'), 'backups', 'Atriom')
    ));

    $entry = collect($records)->first(fn (array $r): bool => str_contains((string) $r['message'], 'backup.run.failed'));

    expect($entry)->not->toBeNull('a failed backup must reach OpsLog regardless of BACKUP_ALERT_EMAIL')
        ->and($entry['level'])->toBe('error');
});

it('records the underlying error, not just that something failed', function () {
    // "Backup failed" without the reason costs whoever reads it the first hour of the incident.
    $records = captureOps(fn () => Event::dispatch(
        new BackupHasFailed(new Exception('sh: mysqldump: command not found'), 'backups', 'Atriom')
    ));

    $context = collect($records)
        ->first(fn (array $r): bool => str_contains((string) $r['message'], 'backup.run.failed'))['context'] ?? [];

    $flat = json_encode($context);

    expect($flat)->toContain('mysqldump')
        ->and($flat)->toContain('backups');
});

it('logs a failed cleanup — that is how the disk fills', function () {
    $records = captureOps(fn () => Event::dispatch(
        new CleanupHasFailed(new Exception('permission denied'), 'backups', 'Atriom')
    ));

    expect(collect($records)->contains(fn (array $r): bool => str_contains((string) $r['message'], 'backup.cleanup.failed')))
        ->toBeTrue();
});

it('logs an unhealthy backup with the checks that failed', function () {
    $records = captureOps(fn () => Event::dispatch(new UnhealthyBackupWasFound(
        'backups',
        'Atriom',
        new Collection([['check' => 'MaximumAgeInDays', 'message' => 'newest backup is 9 days old']]),
    )));

    $entry = collect($records)->first(fn (array $r): bool => str_contains((string) $r['message'], 'backup.unhealthy'));

    expect($entry)->not->toBeNull()
        ->and(json_encode($entry['context']))->toContain('MaximumAgeInDays');
});

it('does not log a successful backup at error level', function () {
    // Paging on success is how an alert channel stops being read — the same failure in a
    // different costume.
    $records = captureOps(fn () => Event::dispatch(new BackupWasSuccessful('backups', 'Atriom')));

    $errors = collect($records)->filter(fn (array $r): bool => $r['level'] === 'error');

    expect($errors)->toBeEmpty('a successful backup must not raise an error');
});

it('logs each failure exactly once', function () {
    // It logged TWICE before: Laravel auto-discovers any `handle*` method in app/Listeners and
    // registers it by type-hint, so the explicit Event::subscribe() registered the same methods a
    // second time. A duplicate error line is how an error log starts getting skimmed. The methods
    // are named `when*` to keep discovery out of it — this pins that, since renaming them back
    // would silently double every entry.
    $records = captureOps(fn () => Event::dispatch(
        new BackupHasFailed(new Exception('boom'), 'backups', 'Atriom')
    ));

    $failures = collect($records)->filter(
        fn (array $r): bool => str_contains((string) $r['message'], 'backup.run.failed')
    );

    expect($failures)->toHaveCount(1, 'the listener is registered more than once');
});

it('registers the listener explicitly, not by auto-discovery alone', function () {
    // The mutation check that this file previously failed: removing Event::subscribe() left every
    // test green, because auto-discovery had silently picked the listener up. Assert the explicit
    // registration exists, so deleting it is a red build rather than a silent change of mechanism.
    $registered = collect(Event::getRawListeners()[BackupHasFailed::class] ?? [])
        ->filter(fn ($listener): bool => str_contains(json_encode($listener), 'LogBackupFailures'));

    expect($registered)->toHaveCount(1, 'LogBackupFailures must be registered exactly once');
});

it('covers every failure event spatie emits', function () {
    // A new failure event added by an upgrade would otherwise be silent by default — which is
    // exactly the state this listener exists to end.
    $subscribed = array_keys((new LogBackupFailures)->subscribe());

    $failureEvents = collect(glob(base_path('vendor/spatie/laravel-backup/src/Events/*.php')))
        ->map(fn (string $f): string => 'Spatie\\Backup\\Events\\'.basename($f, '.php'))
        ->filter(fn (string $c): bool => str_contains($c, 'HasFailed') || str_contains($c, 'Unhealthy'))
        ->values();

    $missing = $failureEvents->reject(fn (string $c): bool => in_array($c, $subscribed, true))->all();

    expect($missing)->toBe([], 'these backup failure events are not logged: '.implode(', ', $missing));
});
