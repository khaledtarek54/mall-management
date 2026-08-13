<?php

use App\Support\OpsLog;
use Illuminate\Support\Arr;
use Sentry\Event;

/**
 * **Every config value must survive `php artisan config:cache`.**
 *
 * WHY THIS EXISTS. `config/sentry.php` declared `before_send` as a closure — the natural way to
 * write a hook, and the way Sentry's own published config writes it. Laravel caches configuration
 * by `var_export()`ing the whole tree into a PHP file, and `var_export()` cannot represent a
 * closure, so `php artisan config:cache` threw `LogicException` and took `php artisan optimize`
 * down with it. Config caching is a step in **every** production deploy, so this was not a
 * performance nicety that degraded — it was a deploy that stopped, and it would have stopped it on
 * the staging box rather than here, because nobody caches config on a laptop.
 *
 * That is the whole shape of the bug worth pinning: a fault that is invisible in development by
 * construction, in a command only the deploy runs. The fix was to move the hook to
 * {@see OpsLog::scrubSentryEvent()} and reference it as `[Class, 'method']` — a valid PHP callable
 * that is also plain exportable data.
 *
 * The first test is deliberately the SAME check Laravel's `ConfigCacheCommand` performs, run
 * against the live config tree, so it fails for any future closure in any config file rather than
 * only for the one that has already been fixed.
 */
it('serializes every configuration value, as config:cache does', function () {
    $offenders = [];

    foreach (Arr::dot(config()->all()) as $key => $value) {
        try {
            // Exactly what Illuminate\Foundation\Console\ConfigCacheCommand does to detect a
            // value the cache file could not hold — matched on purpose, so this test cannot
            // pass while the real command fails.
            eval(var_export($value, true).';');
        } catch (Throwable) {
            $offenders[] = $key;
        }
    }

    expect($offenders)->toBe(
        [],
        // A closure is the usual cause. Move it to a static method and reference it as
        // [Class::class, 'method'] — callable, and exportable.
    );
});

it('keeps sentry before_send a real callable that scrubs, not merely exportable', function () {
    $hook = config('sentry.before_send');

    // Exportability alone would be satisfied by deleting the hook, which is precisely the
    // regression that matters: the PII redaction silently stops running and every outbound
    // event carries whatever a developer put in `extra`.
    expect($hook)->toBeCallable();

    $event = Event::createEvent();
    $event->setExtra(['password' => 'hunter2', 'invoice' => 'INV-001']);

    $returned = $hook($event);

    expect($returned)->not->toBeNull()
        ->and($returned->getExtra()['password'])->toBe('[redacted]')
        ->and($returned->getExtra()['invoice'])->toBe('INV-001');
});
