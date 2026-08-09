<?php

use App\Settings\BillingSettings;
use App\Support\ScheduleSetting;

/**
 * A setting an operator can edit must actually be read (2026-08-09).
 *
 * MF-08 found the shape: the Settings → Billing tab wrote `BillingSettings` while the code read
 * `config('billing.*')`, populated from `env`. Every late-fee value saved on that screen had been
 * silently ignored. The same was true of the FIVE scheduler values — monthly billing day and time,
 * and the three CAM reconciliation ones — because `routes/console.php` built its cron expressions
 * from config.
 *
 * The screen looks like it works either way: it saves, it reloads, it shows the new number. Only
 * the CONSUMER reveals the truth, which is why this test exercises the reader rather than the form.
 */
it('prefers the operator’s setting over the config file', function () {
    config(['billing.monthly_billing_day' => 9]);
    app(BillingSettings::class)->monthly_billing_day = 21;

    expect((int) ScheduleSetting::billing('monthly_billing_day', 'billing.monthly_billing_day', 1))
        ->toBe(21);
});

it('falls back to config when the settings record cannot answer', function () {
    // Boot-time safety: `routes/console.php` runs before a request and sometimes before a database
    // exists — `config:cache` on deploy, a fresh clone, CI ahead of `migrate`. A missing table must
    // produce a stale cron time, never a boot failure.
    config(['billing.cam_reconciliation_day' => 15]);

    app()->bind(BillingSettings::class, function () {
        throw new RuntimeException('no database yet');
    });

    expect((int) ScheduleSetting::billing('cam_reconciliation_day', 'billing.cam_reconciliation_day', 1))
        ->toBe(15);
});

it('falls back rather than scheduling on an empty value', function () {
    // A settings row that exists but holds nothing is not an answer. Taking it literally would
    // build a cron expression from an empty string.
    config(['billing.monthly_billing_time' => '02:00']);
    app(BillingSettings::class)->monthly_billing_time = '';

    expect(ScheduleSetting::billing('monthly_billing_time', 'billing.monthly_billing_time', '03:00'))
        ->toBe('02:00');
});

it('registers the schedule without touching the database', function () {
    // The whole reason for the helper. If this ever throws, deploys break rather than cron drifting.
    $this->artisan('schedule:list')->assertSuccessful();
});
