<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Atriom'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    // Deep link the mobile app handles for password reset. The reset email's
    // button points here with ?token=&email= appended.
    'mobile_reset_url' => env('APP_MOBILE_RESET_URL', env('APP_URL', 'http://localhost').'/reset-password'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    /*
     * Africa/Cairo, not UTC — and this is a MONEY setting, not a display one.
     *
     * The app timezone is what `now()` returns, so it decides which day and which accounting
     * period a document belongs to. Egypt is UTC+2 (UTC+3 in summer), so under UTC every
     * document created between roughly 21:00 and midnight Cairo time is attributed to the
     * PREVIOUS day: a payment taken at 00:30 Cairo on 1 August is stored as 2026-07-31 21:30, and
     * its payment_date, its GL entry_date and its accounting period all land in July. Three hours
     * every single day, silently, on a system whose invariants are all about period attribution
     * (closed periods, month-end close, the GL tie-out).
     *
     * It also fixes what the comment here used to be about: ~20 scheduled jobs (monthly billing,
     * late fees, the SLA scans) declare wall-clock times with no ->timezone(), so they inherit
     * this. `monthlyOn(1, '05:00')` means 05:00 in Cairo now, which is what an operator reading
     * routes/console.php assumes it means.
     *
     * This used to be `env('APP_TIMEZONE', 'UTC')` with a comment telling production to override
     * it — a fail-OPEN default, where forgetting one env var costs you correct books. The test
     * suite pins UTC explicitly in phpunit.xml so determinism is a stated choice rather than a
     * side effect of the production default being wrong.
     */
    'timezone' => env('APP_TIMEZONE', 'Africa/Cairo'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
