<?php

use App\Support\QueueJobSafety;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * A job cannot be re-entered by accident.
 *
 * The two settings that decide it live in different files — a job's `$timeout` and the connection's
 * `retry_after` — so neither could check the other. `ApplyLateFees` shipped with `$timeout = 600`
 * against a `retry_after` of 90 and no overlap guard, which made every nightly run over 90 seconds
 * reclaimable by a second worker while the first was still sweeping the whole arrears backlog. Its
 * sibling `RunMonthlyBilling`, same timeout, had the guard. One of the two was simply written later.
 *
 * This gate is the connection the files could not make.
 */
it('classifies every queued job as serialised or concurrency-safe', function () {
    $onDisk = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $f): string => 'App\\Jobs\\'.basename($f, '.php'))
        ->sort()->values()->all();

    $unclassified = array_values(array_diff($onDisk, QueueJobSafety::classified()));

    expect($unclassified)->toBe([],
        'New queued job(s) with no concurrency decision: '.implode(', ', $unclassified).
        '. Add each to App\\Support\\QueueJobSafety::SERIALISED (and give it WithoutOverlapping) '.
        'or ::CONCURRENCY_SAFE with the reason it is safe.');

    // The reverse: a registry entry for a job that no longer exists reads as a considered decision
    // the next person inherits by accident.
    $stale = array_values(array_diff(QueueJobSafety::classified(), $onDisk));
    expect($stale)->toBe([], 'Registry names job(s) that no longer exist: '.implode(', ', $stale));
});

it('states a reason for every classification', function () {
    // A registry of bare class names is a list, not a decision. The reason is what the next person
    // reads before deciding whether it still holds.
    $blank = collect(QueueJobSafety::SERIALISED)
        ->merge(QueueJobSafety::CONCURRENCY_SAFE)
        ->filter(fn (string $why): bool => strlen(trim($why)) < 40)
        ->keys()->all();

    expect($blank)->toBe([], 'Classifications with no real reason: '.implode(', ', $blank));
});

it('proves each serialised job actually declares the guard, not just the intention', function () {
    // The registry says it must not overlap; this asserts the middleware is really there. A job
    // listed as SERIALISED with no `WithoutOverlapping` is the exact state this gate exists to
    // catch — a stated intention with nothing behind it.
    foreach (array_keys(QueueJobSafety::SERIALISED) as $class) {
        $job = new $class;

        expect(method_exists($job, 'middleware'))->toBeTrue("{$class} declares no middleware().");

        $guards = collect($job->middleware())
            ->filter(fn ($m): bool => $m instanceof WithoutOverlapping);

        expect($guards)->not->toBeEmpty("{$class} is registered SERIALISED but has no WithoutOverlapping.");
    }
});

it('keeps retry_after longer than the longest job timeout', function () {
    // The arithmetic nobody owned. `retry_after` is when the queue decides a reserved job has died;
    // if a job may legitimately run longer than that, the queue hands it to a second worker while
    // the first is still going, and the only symptom is load.
    $longest = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $f): string => 'App\\Jobs\\'.basename($f, '.php'))
        ->map(fn (string $c) => property_exists($c, 'timeout') ? (int) (new $c)->timeout : 0)
        ->max();

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        expect($retryAfter)->toBeGreaterThan($longest,
            "queue.connections.{$connection}.retry_after ({$retryAfter}s) does not exceed the ".
            "longest job timeout ({$longest}s) — a job running that long is handed to a second ".
            'worker while the first is still working.');
    }
});

it('serialises the late-fee sweep per day, so a manual run cannot race the scheduled one', function () {
    // The specific finding. Two runs for the SAME day must collide; two runs for different days
    // are different work and must not — a backfill of yesterday should not be blocked by tonight's.
    $key = fn (?string $date): string => collect((new App\Jobs\ApplyLateFees($date))->middleware())
        ->first(fn ($m): bool => $m instanceof WithoutOverlapping)->key;

    expect($key('2026-08-12'))->toBe($key('2026-08-12'))
        ->and($key('2026-08-12'))->not->toBe($key('2026-08-11'));
});

it('discards a colliding run rather than requeueing it', function () {
    // `dontRelease()`. The sweep is idempotent and runs again tomorrow, so releasing a collided run
    // back onto the queue only repeats work the first run is already doing — and on a job with
    // `$tries = 1` a released job is a job that fails.
    $guard = collect((new App\Jobs\ApplyLateFees)->middleware())
        ->first(fn ($m): bool => $m instanceof WithoutOverlapping);

    $releaseAfter = (new ReflectionProperty(WithoutOverlapping::class, 'releaseAfter'))->getValue($guard);

    expect($releaseAfter)->toBeNull();
});
