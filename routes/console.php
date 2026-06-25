<?php

use App\Jobs\ApplyLateFees;
use App\Jobs\RunMonthlyBilling;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled jobs
|--------------------------------------------------------------------------
|
| Monthly billing — first of each month at 02:00 (configurable in
| config/billing.php). Late fees — every day at 04:00. CAM annual
| reconciliation — Jan 15 at 03:00 (review-only; admin still bills each
| allocation manually unless --auto-bill is passed).
*/

Schedule::job(new RunMonthlyBilling)
    ->monthlyOn(
        (int) config('billing.monthly_billing_day', 1),
        config('billing.monthly_billing_time', '02:00'),
    )
    ->name('atriom-monthly-billing')
    ->withoutOverlapping();

Schedule::job(new ApplyLateFees)
    ->dailyAt('04:00')
    ->name('atriom-late-fees')
    ->withoutOverlapping();

Schedule::command('cam:reconcile')
    ->yearlyOn(
        (int) config('billing.cam_reconciliation_month', 1),
        (int) config('billing.cam_reconciliation_day', 15),
        config('billing.cam_reconciliation_time', '03:00'),
    )
    ->name('atriom-cam-reconcile')
    ->withoutOverlapping();

// Daily housekeeping. Vendor contracts past their end_date get auto-
// expired so the nav-badge "expiring soon" alert stays meaningful
// (audit M15 F-58 / D-43).
Schedule::command('vendors:expire-contracts')
    ->dailyAt('02:30')
    ->name('atriom-expire-vendor-contracts')
    ->withoutOverlapping();

// Monthly housekeeping. Spatie's activitylog:clean drops rows older than
// the config's clean_after_days (default 365) so the audit log doesn't
// accumulate indefinitely (audit M20 F-75 / D-59).
Schedule::command('activitylog:clean')
    ->monthlyOn(1, '05:00')
    ->name('atriom-clean-activity-log')
    ->withoutOverlapping();

// Daily auto-close pass on resolved maintenance requests older than
// config('maintenance.auto_close_after_days') (default 7). Without this
// resolved tickets accumulate forever — operators occasionally need the
// "open" filter to actually mean "current work" (audit M09 F-38 / D-30).
Schedule::command('maintenance:auto-close')
    ->dailyAt('03:00')
    ->name('atriom-auto-close-maintenance')
    ->withoutOverlapping();

// Hourly scan for open requests past their target_resolution_at. Alerts
// managers + maintenance_managers on the asset (or super_admins as
// fallback) via the bell. Idempotent through sla_breach_notified_at, so
// each breach surfaces once.
Schedule::command('maintenance:scan-sla-breaches')
    ->hourly()
    ->name('atriom-scan-sla-breaches')
    ->withoutOverlapping();

// Daily scan for overdue (late-paid) invoices. Alerts the property's Jawad
// owners via the bell. Idempotent through owner_overdue_notified_at.
Schedule::command('billing:scan-overdue-invoices')
    ->dailyAt('06:00')
    ->name('atriom-scan-overdue-invoices')
    ->withoutOverlapping();
