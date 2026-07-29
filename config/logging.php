<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        /*
         * Defaults to the ROTATING channel, not `single`.
         *
         * Laravel's stock default is `single` — one storage/logs/laravel.log that is appended to
         * forever and never pruned. On a live box that file grows until the disk fills, and a
         * full disk does not degrade this app, it stops it: MySQL cannot write, sessions cannot
         * write, uploads cannot write. The failure arrives as a total outage with no warning
         * shot, from a setting nobody thinks of as a setting.
         *
         * `daily` writes laravel-YYYY-MM-DD.log and prunes to LOG_DAILY_DAYS (14). That is the
         * "logrotate guidance" the roadmap asked for: it does not need logrotate, it needs this
         * default to be the safe one instead of a comment telling production to change it.
         */
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'daily')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            // `info`, not `debug`: debug is a development choice, and a default that is wrong
            // unless production remembers an env var is the same fail-open shape as the timezone
            // and the log channel above. Set LOG_LEVEL=debug locally when you want it.
            'level' => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        // Operational/diagnostic events for the money + integration paths
        // (Paymob, ETA, billing, CAM, late fees), written via App\Support\OpsLog.
        // Kept in their own retained file so outages, rejections, and batch
        // summaries are easy to find + alert on. PRODUCTION: add slack for
        // alerting — OPS_LOG_STACK="ops_daily,slack".
        'ops' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('OPS_LOG_STACK', 'ops_daily')),
            'ignore_exceptions' => false,
        ],

        'ops_daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/ops.log'),
            'level' => env('OPS_LOG_LEVEL', 'info'),
            'days' => env('OPS_LOG_DAYS', 60),
            'replace_placeholders' => true,
        ],

        // The alerting sink. Add it to OPS_LOG_STACK in production so money/integration
        // failures reach a human instead of a file nobody reads:
        //     OPS_LOG_STACK="ops_daily,slack"   LOG_SLACK_WEBHOOK_URL=https://hooks.slack…
        //
        // `level` is DELIBERATELY its own env var, not LOG_LEVEL. An alerting threshold and
        // an app-log verbosity are different questions: production runs LOG_LEVEL=warning,
        // and inheriting it here would page someone for every routine warning; staging runs
        // LOG_LEVEL=debug, which would fire on literally everything. Default `error` — the
        // level OpsLog uses for "money did not move" (a failed ETA submission, an unassessed
        // SLA penalty) — so an operator only has to set the webhook to get useful alerts.
        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_SLACK_LEVEL', 'error'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
