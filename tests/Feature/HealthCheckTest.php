<?php

/**
 * The health check must be able to FAIL.
 *
 * A check that cannot go red is decoration — worse than none, because it is
 * believed. So every assertion here breaks something and demands a 503, rather
 * than confirming a green box stays green.
 *
 * The scheduler case is the one that justifies the whole endpoint: every
 * scheduled monitor (backup:monitor included) can only report a problem while
 * the scheduler is alive, so none of them can report that the scheduler has
 * STOPPED. Only something outside the box can.
 */

use App\Support\Health;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    // A healthy baseline: fresh heartbeat, a recent backup archive, no job backlog.
    Health::stampHeartbeat();

    Storage::fake('backups');
    Storage::disk('backups')->put('Atriom/2026-07-29-01-00-00.zip', 'archive');

    config()->set('health.token', null);
});

afterEach(function () {
    File::delete(Health::heartbeatPath());
    Carbon::setTestNow();
});

it('passes when everything is in order', function () {
    $result = Health::run();

    expect($result['status'])->toBe('ok', 'Unhealthy: '.json_encode($result['checks']));
});

it('fails, and answers 503, when the scheduler has stopped', function () {
    // The failure nothing else can see. A dead cron takes the billing run, the
    // GL sync, the nightly backup AND every alarm with it, and looks like a
    // quiet night.
    File::put(Health::heartbeatPath(), (string) now()->subHour()->getTimestamp());

    $result = Health::run();

    expect($result['checks']['scheduler']['ok'])->toBeFalse()
        ->and($result['status'])->toBe('degraded');

    $this->get('/health')->assertStatus(503);
});

it('fails when the scheduler has never run at all', function () {
    File::delete(Health::heartbeatPath());

    expect(Health::run()['checks']['scheduler']['ok'])->toBeFalse();
});

it('does not read the heartbeat from the database or cache', function () {
    // Both are database-backed in this app. If the heartbeat lived in either, a
    // DB outage would ALSO report "scheduler dead" — burying the real fault
    // under a second, wrong alarm.
    Health::stampHeartbeat();

    expect(File::exists(Health::heartbeatPath()))->toBeTrue()
        ->and(cache()->get('scheduler-heartbeat'))->toBeNull();
});

it('fails when the newest backup is too old', function () {
    Storage::fake('backups'); // no archives at all
    expect(Health::run()['checks']['backups']['ok'])->toBeFalse();
});

it('fails when jobs are failing', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
    ]);

    // Every failed job here is a tax document or a journal entry that did not
    // happen, so the threshold is zero.
    expect(Health::run()['checks']['queue']['ok'])->toBeFalse();
});

it('fails when the queue has backed up behind a stopped worker', function () {
    config()->set('health.max_pending_jobs', 2);

    foreach (range(1, 3) as $i) {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
    }

    expect(Health::run()['checks']['queue']['ok'])->toBeFalse();
});

/* ---- what the endpoint discloses ----------------------------------------- */

it('tells an uptime monitor up or down and nothing else', function () {
    File::delete(Health::heartbeatPath());

    $response = $this->get('/health');

    $response->assertStatus(503)->assertExactJson(['status' => 'degraded']);

    // No check names, no error messages, no internal state to an anonymous caller.
    expect($response->json())->not->toHaveKey('checks');
});

it('returns detail only for a caller holding the token', function () {
    config()->set('health.token', 'a-real-token');

    // Positives first, and flushHeaders() between: withHeader() sets a DEFAULT
    // header on the TestCase that persists into every later request, so a
    // wrong-token call would keep poisoning the ones after it.
    $this->get('/health?token=a-real-token')->assertJsonStructure(['status', 'checks']);

    $this->flushHeaders()
        ->withHeader('X-Health-Token', 'a-real-token')
        ->get('/health')->assertJsonStructure(['status', 'checks']);

    $this->flushHeaders()->get('/health')->assertJsonMissingPath('checks');
    $this->flushHeaders()->get('/health?token=wrong')->assertJsonMissingPath('checks');

    $this->flushHeaders()
        ->withHeader('X-Health-Token', 'wrong')
        ->get('/health')->assertJsonMissingPath('checks');
});

it('never exposes detail when no token is configured', function () {
    // An unset token must mean "detail is off", not "detail is open".
    config()->set('health.token', null);

    $this->get('/health?token=')->assertJsonMissingPath('checks');
    $this->get('/health')->assertJsonMissingPath('checks');
});

it('answers 200 while healthy, so a monitor can distinguish the two', function () {
    $this->get('/health')->assertStatus(200)->assertExactJson(['status' => 'ok']);
});

/* ---- the CLI form -------------------------------------------------------- */

it('exits non-zero from the console when unhealthy', function () {
    File::delete(Health::heartbeatPath());

    $this->artisan('atriom:health')->assertExitCode(1);
});

it('exits zero from the console when healthy', function () {
    $this->artisan('atriom:health')->assertExitCode(0);
});

it('runs the heartbeat every minute on the scheduler', function () {
    $names = collect(app(Schedule::class)->events())
        ->map(fn ($e) => $e->description ?? '')
        ->implode(' ');

    expect($names)->toContain('atriom-scheduler-heartbeat');
});
