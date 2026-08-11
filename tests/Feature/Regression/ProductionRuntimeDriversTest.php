<?php

use App\Support\Health;

/**
 * Production must not run cache, session or queue on the database.
 *
 * `.env.example` ships all three as `database`; `docs/INFRASTRUCTURE.md` §5 calls Redis
 * non-negotiable; and until now nothing sat between those two facts. A box provisioned by copying
 * the example file reports green health while every `Cache::lock()` crosses the network to an
 * off-box MySQL.
 *
 * The locks are why this is a correctness check and not a performance one:
 * `AllocatesDocumentNumber` holds a *blocking* lock across every numbered document's insert, and
 * `MonthlyBillingService`'s double-bill guard is a cache lock with **no DB unique index behind
 * it** — the lock is the entire guard, and its own comment says so.
 */
it('fails in production while cache, session or queue run on the database', function () {
    app()['env'] = 'production';
    config()->set('cache.default', 'database');
    config()->set('session.driver', 'database');
    config()->set('queue.default', 'database');

    $check = Health::run()['checks']['runtime_drivers'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('cache')
        ->and($check['detail'])->toContain('session')
        ->and($check['detail'])->toContain('queue');
});

it('names only the drivers that are actually wrong', function () {
    app()['env'] = 'production';
    config()->set('cache.default', 'redis');
    config()->set('session.driver', 'redis');
    config()->set('queue.default', 'database');

    $check = Health::run()['checks']['runtime_drivers'];

    // A check that says "something is wrong" without saying which is a check people stop reading.
    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('queue')
        ->and($check['detail'])->not->toContain('session');
});

it('passes in production once all three are off the database', function () {
    app()['env'] = 'production';
    config()->set('cache.default', 'redis');
    config()->set('session.driver', 'redis');
    config()->set('queue.default', 'redis');

    expect(Health::run()['checks']['runtime_drivers']['ok'])->toBeTrue();
});

it('stays quiet outside production — local and CI run on the database deliberately', function () {
    // Without this the check would turn every developer's health output red and be ignored.
    config()->set('cache.default', 'database');
    config()->set('session.driver', 'database');
    config()->set('queue.default', 'database');

    expect(Health::run()['checks']['runtime_drivers']['ok'])->toBeTrue();
});
