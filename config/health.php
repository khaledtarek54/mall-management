<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health-check thresholds
    |--------------------------------------------------------------------------
    |
    | Read by App\Support\Health, which backs both /health and `atriom:health`.
    | Every value is the point at which a human should be told, not the point at
    | which something is technically wrong — a check that cries wolf gets muted,
    | and a muted check is worse than none.
    |
    */

    /*
     * The scheduler stamps a heartbeat every minute. Five minutes allows for a
     * slow host or one skipped tick without paging; anything longer means cron
     * has actually stopped, taking billing, GL sync and BACKUPS with it.
     */
    'max_scheduler_age_seconds' => (int) env('HEALTH_MAX_SCHEDULER_AGE', 300),

    /*
     * Backups run nightly. 48h tolerates exactly one missed night — enough not
     * to page over a transient, short enough that two nights without a backup
     * is never normal.
     */
    'max_backup_age_hours' => (int) env('HEALTH_MAX_BACKUP_AGE_HOURS', 48),

    /*
     * Any failed job is worth looking at here: this app queues ETA submissions
     * and GL posting, so a failure is a tax document or a journal entry that did
     * not happen. Raise it only if you have a known-noisy job.
     */
    'max_failed_jobs' => (int) env('HEALTH_MAX_FAILED_JOBS', 0),

    /*
     * A backlog this size means the worker is not running rather than that the
     * app is busy — the same silent failure mode as a dead scheduler.
     */
    'max_pending_jobs' => (int) env('HEALTH_MAX_PENDING_JOBS', 500),

    /*
     * Detailed output (which check failed and why) is only returned when this
     * token is supplied via ?token= or the X-Health-Token header. Unauthenticated
     * callers get status alone, so an uptime monitor can poll the endpoint
     * without publishing the app's internal state to the internet.
     *
     * Unset = detail is never exposed publicly.
     */
    'token' => env('HEALTH_TOKEN'),

];
