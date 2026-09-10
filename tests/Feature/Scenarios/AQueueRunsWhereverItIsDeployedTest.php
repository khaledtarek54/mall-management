<?php

use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;

/**
 * A supervisor that does not exist processes nothing, and says nothing.
 *
 * `ProvisioningPlan::deploy()` picks the FIRST environment key matching `APP_ENV`
 * and `return`s silently when none matches; `add()` is likewise skipped for any
 * supervisor left at `maxProcesses => 0`. Neither is an error. Horizon boots,
 * `horizon:status` prints "Horizon is running", the dashboard renders its empty
 * lists — and the monthly billing run, the ledger sync and the Paymob callback
 * have no worker at all.
 *
 * That is not hypothetical: Horizon's PUBLISHED `config/horizon.php` names
 * `production` and `local` only, and this project has a third tier —
 * `App\Support\Deployment` — whose whole point is that `staging` is neither. A
 * default install on the soak box would have hit exactly this.
 *
 * The teeth below are the ones the config file cannot enforce about itself.
 */
it('gives every environment this app is deployed to a supervisor that actually runs', function () {
    $plan = ProvisioningPlan::get('conformance-master');

    // `an-environment-nobody-anticipated` is the point of the `*` entry, not padding. For a WORKER,
    // an unknown environment inheriting "the stricter treatment" cannot mean processing nothing —
    // it means the conservative supervisor. A box brought up as `APP_ENV=staging2` for a migration
    // rehearsal must still run the queue.
    $environments = ['production', 'staging', 'local', 'an-environment-nobody-anticipated'];

    foreach ($environments as $environment) {
        // Resolved exactly as `deploy()` resolves it — the same `Str::is` over the same insertion
        // order — so this cannot drift from the code it is standing in for.
        $supervisors = collect($plan->parsed)->first(
            fn ($_, string $name): bool => Str::is($name, $environment)
        );

        expect($supervisors)->not->toBeNull(
            "horizon.environments has no entry matching [{$environment}]. Horizon would start there ".
            'and provision no supervisors, processing nothing, with no error anywhere.'
        );

        $running = collect($supervisors)->filter(fn ($options): bool => $options->maxProcesses > 0);

        expect($running)->not->toBeEmpty(
            "Every supervisor for [{$environment}] sits at maxProcesses => 0, which ProvisioningPlan ".
            'skips just as silently as a missing environment.'
        );
    }
});

it('keeps the catch-all environment last so it cannot shadow a named one', function () {
    // `first()` stops at the first match, so a `*` placed above `production` would swallow it and
    // production would silently run the fallback supervisor. Asserted by resolving the KEY rather
    // than the options: the two entries hold the same values today, so comparing values would pass
    // whatever the order.
    $names = array_keys(config('horizon.environments'));

    // NOT `toContain('*', $message)`: that matcher is VARIADIC, so a message becomes a second
    // value it looks for and the assertion fails on the message itself.
    expect(in_array('*', $names, true))->toBeTrue(
        'No catch-all environment in horizon.environments: an unanticipated APP_ENV runs no workers.'
    );

    foreach (['production', 'staging', 'local'] as $environment) {
        $matched = collect($names)->first(fn (string $name): bool => Str::is($name, $environment));

        expect($matched)->toBe($environment,
            "[{$environment}] resolves to the [{$matched}] entry — a wildcard is ordered above it."
        );
    }
});

it('keeps every supervisor timeout below the connection retry_after', function () {
    // The worker half of the arithmetic `QueueJobSafetyConformanceTest` proves for jobs. `timeout`
    // governs any job that declares none of its own (`Worker::timeoutForJob()` prefers the job's),
    // and if it ever reached `retry_after` the queue would hand a still-running job to a second
    // worker — the double-bill config/queue.php's own docblock was written about.
    $plan = ProvisioningPlan::get('conformance-master');

    $checked = 0;

    foreach ($plan->parsed as $environment => $supervisors) {
        foreach ($supervisors as $name => $options) {
            $retryAfter = (int) config("queue.connections.{$options->connection}.retry_after");

            expect($retryAfter)->toBeGreaterThan(0,
                "[{$environment}.{$name}] runs on connection [{$options->connection}], which declares no retry_after."
            );

            expect((int) $options->timeout)->toBeLessThan($retryAfter,
                "[{$environment}.{$name}] timeout ({$options->timeout}s) reaches retry_after ({$retryAfter}s) on ".
                "[{$options->connection}] — a job still running is handed to a second worker."
            );

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0, 'This swept no supervisors and proved nothing.');
});

it('watches the queue that jobs are actually dispatched to', function () {
    // A supervisor watching the wrong queue name is the missing-environment failure wearing a
    // different hat: everything runs, nothing is processed.
    $plan = ProvisioningPlan::get('conformance-master');

    foreach ($plan->parsed as $environment => $supervisors) {
        foreach ($supervisors as $name => $options) {
            $default = (string) (config("queue.connections.{$options->connection}.queue") ?: 'default');
            $watched = is_array($options->queue) ? $options->queue : explode(',', (string) $options->queue);

            expect(in_array($default, $watched, true))->toBeTrue(
                "[{$environment}.{$name}] watches [".implode(',', $watched).'] but jobs on '.
                "[{$options->connection}] are dispatched to [{$default}]."
            );
        }
    }
});

it('watches any named queue a job routes itself to', function () {
    // Today no job names a queue, so every supervisor watching `default` is enough. The moment one
    // does — `->onQueue('reports')`, `public $queue = 'reports'` — it stops being enough, and the
    // symptom is a job that is accepted, queued and never run. This is the only tooth that would
    // notice.
    $plan = ProvisioningPlan::get('conformance-master');

    $watched = collect($plan->parsed)
        ->flatMap(fn ($supervisors) => collect($supervisors)->flatMap(
            fn ($options): array => is_array($options->queue) ? $options->queue : explode(',', (string) $options->queue)
        ))
        ->unique()->values()->all();

    expect($watched)->not->toBeEmpty('No supervisor watches any queue at all.');

    $files = glob(app_path('Jobs/*.php'));

    expect($files)->not->toBeEmpty('Swept no job files — this proved nothing.');

    $named = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if (preg_match_all('/onQueue\(\s*[\'"]([a-z0-9_\-]+)[\'"]/i', $source, $m)) {
            $named = [...$named, ...$m[1]];
        }

        if (preg_match_all('/\$queue\s*=\s*[\'"]([a-z0-9_\-]+)[\'"]/i', $source, $m)) {
            $named = [...$named, ...$m[1]];
        }
    }

    // Asserted as a DIFF rather than in a loop: today no job names a queue, so a loop body would
    // never run and the test would pass having asserted nothing — which is how a gate comes to
    // report on a set it is not examining.
    $uncovered = array_values(array_diff(array_unique($named), $watched));

    expect($uncovered)->toBe([],
        'Job(s) route themselves to queue(s) no Horizon supervisor watches: '.implode(', ', $uncovered).
        '. Such a job is accepted, queued and never run.'
    );
});
