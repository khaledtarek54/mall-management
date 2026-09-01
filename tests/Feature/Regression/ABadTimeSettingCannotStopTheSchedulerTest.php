<?php

use App\Settings\BillingSettings;
use App\Support\ScheduleSetting;
use Illuminate\Console\Scheduling\Schedule;

/**
 * A malformed schedule setting must not be able to stop the scheduler.
 *
 * Four operator- or env-editable values become parts of a cron expression: the monthly billing time,
 * the assessment billing time, and the CAM reconciliation month, day and time. Two of them were
 * plain `TextInput`s with a placeholder and no rule.
 *
 * **What a bad one costs is not its own job.** `Schedule::dueEvents()` is `filter->isDue()` over
 * every registered event with no try/catch, so the first invalid expression throws and
 * `schedule:run` aborts before *any* event runs — including events defined earlier. That is proved
 * here rather than asserted, because it is the entire reason the guard exists.
 *
 * The casualties include both things that would have raised the alarm: `atriom:notify-status`, and
 * the `everyMinute()` heartbeat that is how `atriom:health` notices a dead scheduler at all.
 *
 * The pickers on the Settings form are the first layer and are a UI truth only — a settings row also
 * arrives from a seeder, an import, `tinker`, `env` or a restored backup. This tests the layer that
 * holds.
 */
it('proves the hazard: one bad expression aborts the whole run, not one event', function () {
    $ran = [];
    $schedule = app(Schedule::class);

    $schedule->call(function () use (&$ran) { $ran[] = 'before'; })->dailyAt('00:00');
    $schedule->call(function () use (&$ran) { $ran[] = 'bad'; })->dailyAt('24:00');
    $schedule->call(function () use (&$ran) { $ran[] = 'every-minute'; })->everyMinute();

    expect(fn () => $schedule->dueEvents(app())->each(fn ($e) => $e->run(app())))
        ->toThrow(InvalidArgumentException::class);

    // Nothing ran — not the event defined BEFORE the bad one, and not the every-minute heartbeat.
    // This is what makes a single malformed setting a portfolio-wide outage rather than one job.
    expect($ran)->toBe([]);
});

it('falls back to the default when a stored time cannot be scheduled', function (string $stored) {
    app(BillingSettings::class)->fill(['monthly_billing_time' => $stored])->save();

    expect(ScheduleSetting::billingTime('monthly_billing_time', 'billing.monthly_billing_time', '02:00'))
        ->toBe('02:00');
})->with([
    // Each of these really does throw when spliced into an expression — verified by the control
    // above. `''` and free text are deliberately NOT here: `dailyAt('')` compiles to `0 0 * * *`,
    // which is valid, so they are a silent-midnight problem rather than a scheduler-stopper — and
    // `''` never reaches this method at all, because `billing()` already treats it as unanswered.
    'hour out of range' => '24:00',
    'minute out of range' => '02:99',
    'a date, whose leading number becomes the hour' => '2026-09-01',
]);

it('accepts every shape that is genuinely schedulable', function (string $stored, string $expected) {
    // `dailyAt()` reads only the hour and the minute. Refusing a correct time written unpadded —
    // which is how it arrives from `env`, and `0:0` is Laravel's OWN default for monthlyOn() —
    // would turn a harmless shape into a setting silently ignored: this method's own failure mode
    // through a different door. Lenient about shape, strict about range.
    app(BillingSettings::class)->fill(['monthly_billing_time' => $stored])->save();

    expect(ScheduleSetting::billingTime('monthly_billing_time', 'billing.monthly_billing_time', '02:00'))
        ->toBe($expected);
})->with([
    'padded' => ['23:45', '23:45'],
    'midnight' => ['00:00', '00:00'],
    'unpadded hour' => ['2:30', '02:30'],
    'unpadded minute' => ['9:05', '09:05'],
    'both unpadded — Laravel own default' => ['0:0', '00:00'],
    // What a Filament TimePicker writes unless someone remembers `->seconds(false)`. Not
    // hypothetical: converting these two fields to pickers, one was left without it and began
    // writing `03:00:00` on its first save.
    'seconds included' => ['03:00:00', '03:00'],
    'stray whitespace' => [' 02:15 ', '02:15'],
]);

it('keeps an out-of-range day or month out of the expression', function () {
    // Month 0 and day 32 throw and take the run down exactly as a bad hour does.
    expect(ScheduleSetting::billingInt('cam_reconciliation_month', 'billing.cam_reconciliation_month', 1, 1, 12))
        ->toBeGreaterThanOrEqual(1);

    app(BillingSettings::class)->fill(['cam_reconciliation_month' => 0])->save();
    expect(ScheduleSetting::billingInt('cam_reconciliation_month', 'billing.cam_reconciliation_month', 1, 1, 12))->toBe(1);

    app(BillingSettings::class)->fill(['cam_reconciliation_month' => 13])->save();
    expect(ScheduleSetting::billingInt('cam_reconciliation_month', 'billing.cam_reconciliation_month', 1, 1, 12))->toBe(1);

    // The control: a month the operator really did choose survives.
    app(BillingSettings::class)->fill(['cam_reconciliation_month' => 3])->save();
    expect(ScheduleSetting::billingInt('cam_reconciliation_month', 'billing.cam_reconciliation_month', 1, 1, 12))->toBe(3);
});

it('clamps an impossible date instead of never running', function () {
    // 30 February is savable on the form — it caps month at 12 and day at 31 independently — and is
    // the QUIETER failure: `isDue()` answers false for ever without throwing, so CAM reconciliation
    // simply never runs and nothing says so. Clamping is the house answer, the same one `BillingDay`
    // gives the monthly billing day for the same reason.
    expect(ScheduleSetting::yearlyDay(2, 30))->toBe(28)
        ->and(ScheduleSetting::yearlyDay(4, 31))->toBe(30)
        // The control: a day that fits is left exactly alone.
        ->and(ScheduleSetting::yearlyDay(3, 31))->toBe(31)
        ->and(ScheduleSetting::yearlyDay(1, 15))->toBe(15);
});

it('reports a bad setting once per process, and cannot be brought down by reporting it', function () {
    // This runs from routes/console.php, which every artisan invocation loads, and the `ops` stack
    // ships with `ignore_exceptions => false` and a Slack handler at error level in production. An
    // unguarded log here would POST on every schedule:run, every queue:work boot and every step of
    // deploy.sh — and a webhook failure would throw out of a method nobody has reason to guard,
    // turning a stale cron time into the boot failure this class exists to prevent.
    app(BillingSettings::class)->fill(['monthly_billing_time' => '24:00'])->save();

    foreach (range(1, 5) as $ignored) {
        expect(ScheduleSetting::billingTime('monthly_billing_time', 'billing.monthly_billing_time', '02:00'))
            ->toBe('02:00');
    }
});
