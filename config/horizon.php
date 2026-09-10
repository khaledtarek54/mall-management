<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    | The ENVIRONMENT is in the default deliberately. Production and staging are
    | kept apart today only by `REDIS_PREFIX` (`atr_p_` / `atr_s_`) and separate
    | Redis databases — a convention, documented in `.env.example`, that a box
    | provisioned in a hurry can simply not follow. Two environments sharing one
    | Horizon keyspace do not error: they interleave each other's metrics, job
    | records and `horizon:terminate` commands, so staging's dashboard shows
    | production's jobs and a staging deploy terminates production's workers.
    | Putting the environment in the key makes that separation structural.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_'.env('APP_ENV', 'local').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    // A FAILURE outlives the week Horizon ships with. The staging soak runs a
    // month (docs/qa/STAGING-SOAK-2026-09.md), and the whole reason for adopting
    // Horizon there is to read what failed and why — at 10080 (7 days) the first
    // three weeks of evidence would be gone before anyone came to look. 43200 is
    // 30 days. The `recent`/`pending`/`completed` windows are left alone: those
    // are throughput minutes, not evidence, and they are what Redis actually
    // grows on.
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 43200,
        'failed' => 43200,
        'monitored' => 43200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    | RAISED FROM HORIZON'S PUBLISHED 64, WHICH CRASH-LOOPS THIS APPLICATION.
    | Measured on the staging box 2026-09-10: the master boots at 83MB — it loads
    | the full framework, and this app registers three Filament panels and 66
    | resources at boot — so it exceeded the limit within a second of starting,
    | every time. The failure is a nasty one to read: the master prints
    | "INFO Horizon started successfully", THEN prints the memory line and exits
    | 12, so `journalctl` shows a successful start on repeat and systemd's
    | `Restart=always` hides it as a service stuck in `activating`.
    |
    | 256 is ~3x the measured boot footprint: enough that a supervisor process
    | cannot trip it in normal running, low enough that a genuine leak still
    | terminates and restarts the master rather than growing without bound. The
    | per-WORKER limit below is a different number and is deliberately left at
    | 128 — that is what the bare `queue:work` unit ran with (its own default)
    | for the whole soak, so it is the one figure here with real evidence behind
    | it, and exceeding it restarts a worker gracefully rather than crash-looping.
    |
    */

    'memory_limit' => 256,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | These values REPLACE the systemd unit this supervisor takes over from:
    |
    |     php artisan queue:work redis --tries=3 --max-time=3600 --sleep=3
    |
    | Every departure from Horizon's published default below is there because the
    | published default would have quietly changed what that unit did. Read them
    | before touching one.
    |
    | `tries` — Horizon ships 1. The unit ran 3. Shipping the default would have
    | dropped two retries from every job on the box, so a transient Redis blip or
    | a deadlocked money write would go straight to `failed_jobs` where today it
    | recovers on its own.
    |
    | `maxTime` — Horizon ships 0 (never recycle). The unit ran 3600, i.e. the
    | worker is replaced hourly, which is what keeps a long-lived process from
    | accumulating whatever a 24-source ledger sync leaks.
    |
    | `timeout` — MUST stay below `queue.connections.redis.retry_after` (900), or
    | a job still running is handed to a second worker while the first is working:
    | the double-bill that file's own docblock records. Note this governs only a
    | job that declares NO timeout of its own — `Worker::timeoutForJob()` prefers
    | the job's `$timeout`, and `RunMonthlyBilling`/`ApplyLateFees` both carry 600
    | themselves. 600 here gives an untimed job (a Filament export, a push) the
    | same ceiling, well clear of retry_after.
    |
    | `maxProcesses` — deliberately ONE, the same single worker the box runs
    | today. Concurrency would be safe: `App\Support\QueueJobSafety` classifies
    | every job as SERIALISED (carrying `WithoutOverlapping`, whose lock is Redis
    | and so holds across processes) or CONCURRENCY_SAFE with its reason. It stays
    | at one because the staging soak exists to watch THIS topology for a month —
    | raising it changes the thing being observed, which is a tuning decision to
    | take deliberately after the soak, not a side effect of adopting Horizon.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 600,
            'sleep' => 3,
            'nice' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | AN ENVIRONMENT WITH NO ENTRY HERE RUNS NO WORKERS AT ALL, AND SAYS NOTHING.
    |
    | `ProvisioningPlan::deploy()` picks the first key matching the environment
    | and `return`s silently when none does; `add()` is then never reached, so
    | Horizon boots, `horizon:status` reports "running", the dashboard renders —
    | and not one job is processed. The published file names `production` and
    | `local` only, so `APP_ENV=staging` (this project's third tier, see
    | `App\Support\Deployment`) would have hit exactly that on the soak box.
    |
    | Hence the `*` catch-all. For a WORKER, an unanticipated environment name
    | inheriting "the stricter treatment" cannot mean processing nothing — the
    | billing run, the ledger sync and the Paymob callback would all stop with no
    | error anywhere. Stricter here means the CONSERVATIVE supervisor: one
    | process, three tries, hourly recycle. Matching is insertion-ordered, so the
    | wildcard must stay LAST or it would swallow `production`.
    |
    | `AQueueRunsWhereverItIsDeployedTest` fails the build if this stops being
    | true — a supervisor missing, or one left at `maxProcesses => 0`, which
    | `deploy()` skips just as silently.
    |
    */

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],

        'staging' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],

        '*' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
