<?php

/*
|--------------------------------------------------------------------------
| Production defaults must be safe when nobody sets anything
|--------------------------------------------------------------------------
| Two settings that shipped wrong-by-default with a comment telling production to override them.
| A default that is only correct if someone remembers an env var is fail-OPEN: forget it once and
| nothing fails, it just quietly costs you.
|
| 1. THE APPLICATION TIMEZONE IS A MONEY SETTING.
| `config('app.timezone')` is what `now()` returns, so it decides which day — and therefore which
| accounting period — a document belongs to. Egypt is UTC+2, UTC+3 in summer.
|
| It used to default to UTC, with a comment telling production to override it via APP_TIMEZONE.
| Under UTC, every document created between roughly 21:00 and midnight Cairo is attributed to the
| PREVIOUS day: a payment taken at 00:30 Cairo on 1 August is stored as 2026-07-31 21:30, and its
| payment_date, its GL entry_date and its accounting period all land in July. Three hours a day,
| silently, on a system whose invariants are all about period attribution — closed periods, the
| month-end close, the GL tie-out.
|
| A default that is wrong unless someone remembers an env var is a fail-OPEN default. The correct
| value is now the default; the suite pins UTC in phpunit.xml so its determinism is a stated
| choice rather than a side effect of production being misconfigured.
*/

use Illuminate\Support\Carbon;

it('defaults to Cairo when nothing sets APP_TIMEZONE', function () {
    // Read the config file's own expression rather than config('app.timezone') — the suite pins
    // APP_TIMEZONE=UTC, so the live value here is UTC by design. What must not regress is the
    // DEFAULT a deploy gets when it sets nothing.
    // Matched on the ASSIGNMENT, not anywhere in the file: the doc comment above it quotes the
    // old `env('APP_TIMEZONE', 'UTC')` expression to explain what changed, and a whole-file
    // search finds that and fails on the explanation.
    $assignment = "'timezone' => env('APP_TIMEZONE', 'Africa/Cairo'),";

    expect(file_get_contents(config_path('app.php')))->toContain($assignment);
});

it('keeps the test suite on a fixed offset, deliberately', function () {
    // Pinned in phpunit.xml. Not because UTC is right for the product — it isn't — but because
    // the suite asserts fixed dates and must not behave differently in February and July.
    expect(config('app.timezone'))->toBe('UTC')
        ->and(file_get_contents(base_path('phpunit.xml')))
        ->toContain('<env name="APP_TIMEZONE" value="UTC"/>');
});

it('attributes a late-night Cairo document to the right day and period', function () {
    // The actual bug, stated as arithmetic: 00:30 on the 1st, in Cairo.
    $cairoMidnightish = Carbon::parse('2026-08-01 00:30:00', 'Africa/Cairo');

    // What UTC recorded — the previous month.
    expect($cairoMidnightish->copy()->setTimezone('UTC')->format('Y-m'))->toBe('2026-07')
        // What Cairo records — the month the money actually moved.
        ->and($cairoMidnightish->copy()->setTimezone('Africa/Cairo')->format('Y-m'))->toBe('2026-08');
});

it('leaves the scheduled jobs meaning what routes/console.php says they mean', function () {
    // ~20 jobs declare wall-clock times (dailyAt('02:30'), monthlyOn(1, '05:00')) and NONE call
    // ->timezone(), so they all inherit the app timezone. With the default fixed, those strings
    // now mean Cairo time — which is what an operator reading the file assumes.
    $console = file_get_contents(base_path('routes/console.php'));

    expect(substr_count($console, '->timezone('))->toBe(0,
        'A per-schedule ->timezone() would silently diverge from the app timezone this test pins.')
        ->and($console)->toContain('dailyAt(');
});

it('writes rotating, pruned logs by default — an unbounded file fills the disk and stops the app', function () {
    // Laravel's stock `stack` resolves to `single`: one storage/logs/laravel.log, appended to
    // forever, never pruned. On a live box that fills the disk, and a full disk does not slow this
    // app down — it stops it, because MySQL, sessions and uploads all lose their writes.
    //
    // Asserted on the config source and the deploy template rather than the resolved value: the
    // local .env deliberately sets LOG_STACK=single (fine for dev), so config('...') here reports
    // the developer's choice, not the default a deploy inherits.
    expect(file_get_contents(config_path('logging.php')))
        ->toContain("env('LOG_STACK', 'daily')")
        // debug is a development choice; a default that is wrong unless production remembers an
        // env var is the same fail-open shape as the timezone.
        ->toContain("env('LOG_LEVEL', 'info')");

    // .env.example is what production actually copies — pinning `single` there would defeat the
    // config default entirely, which is exactly what it used to do.
    expect(file_get_contents(base_path('.env.example')))
        ->toContain('LOG_STACK=daily')
        ->not->toContain("\nLOG_STACK=single");
});
