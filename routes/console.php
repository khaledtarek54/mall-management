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
