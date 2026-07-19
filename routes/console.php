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

// Monthly straight-line depreciation for the fixed-asset register (module 23).
// Idempotent (one charge per asset+month), so a re-run is harmless.
Schedule::command('accounting:post-depreciation')
    ->monthlyOn(28, '03:30')
    ->name('atriom-post-depreciation')
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
Schedule::command('requests:auto-close')
    ->dailyAt('03:00')
    ->name('atriom-auto-close-maintenance')
    ->withoutOverlapping();

// Daily scan raising preventive-maintenance work orders for plans that are due.
// Idempotent + lock-safe (advances next_due_date), so a re-run is harmless.
Schedule::command('maintenance:generate-preventive')
    ->dailyAt('02:30')
    ->name('atriom-generate-preventive-maintenance')
    ->withoutOverlapping();

// Hourly scan for open requests past their target_resolution_at. Alerts
// managers + maintenance_managers on the asset (or super_admins as
// fallback) via the bell. Idempotent through sla_breach_notified_at, so
// each breach surfaces once.
Schedule::command('requests:scan-sla-breaches')
    ->hourly()
    ->name('atriom-scan-sla-breaches')
    ->withoutOverlapping();

// Corrective work orders past their SLA (FR-CM-08). Separate from the tenant-request scan
// above: different subject, different table, its own idempotency stamp.
Schedule::command('maintenance:scan-wo-sla-breaches')
    ->hourly()
    ->name('atriom-scan-wo-sla-breaches')
    ->withoutOverlapping();

// Daily scan for overdue (late-paid) invoices. Alerts the property's Jawad
// owners via the bell. Idempotent through owner_overdue_notified_at.
Schedule::command('billing:scan-overdue-invoices')
    ->dailyAt('06:00')
    ->name('atriom-scan-overdue-invoices')
    ->withoutOverlapping();

// FR-INV-03 — each mall's own shortages, once per shortage rather than once per run.
// Daily, not hourly: a reorder level is a restocking hint, not a deadline, and an alert that
// repeats faster than anyone can act on it is an alert people learn to ignore.
Schedule::command('inventory:scan-low-stock')->dailyAt('07:30');

// Apply due contractual rent escalations (fixed_percent) to active leases and roll
// next_escalation_date forward a year. Idempotent + lock-safe; a missed anniversary would
// otherwise leak revenue. Daily so a due lease escalates the day it comes due.
Schedule::command('leases:apply-escalations')
    ->dailyAt('05:30')
    ->name('atriom-apply-rent-escalations')
    ->withoutOverlapping();

// Report post-dated cheques matured-but-uncleared (money the register expected by now) + those
// maturing soon. Read-only observability (OpsLog); the register itself is the maturity schedule.
Schedule::command('pdc:scan-maturing')
    ->dailyAt('07:45')
    ->name('atriom-scan-maturing-cheques')
    ->withoutOverlapping();

// Daily reminder to tenants about their own overdue invoices (email + bell +
// mobile push). Separate stamp (tenant_overdue_notified_at) so it fires once
// per invoice, independently of the owner alert above.
Schedule::command('billing:remind-overdue-tenants')
    ->dailyAt('06:15')
    ->name('atriom-remind-overdue-tenants')
    ->withoutOverlapping();

// Daily reminder to tenants whose active lease is approaching expiry (email +
// bell + mobile push), nudging renewal. Idempotent via
// leases.expiry_reminder_notified_at — each lease reminds once.
Schedule::command('leases:remind-expiring')
    ->dailyAt('07:00')
    ->name('atriom-remind-expiring-leases')
    ->withoutOverlapping();

// Daily auto-provision of the current year's marketing budget for every
// property (idempotent). Users never hand-create budgets — they appear here,
// funded by the levy, and at year rollover the new year's budgets show up.
Schedule::command('marketing:ensure-budgets')
    ->dailyAt('01:30')
    ->name('atriom-ensure-marketing-budgets')
    ->withoutOverlapping();

// Post/reconcile general-ledger entries for the recent window (idempotent,
// self-healing). Keeps the books current; the one-time historical backfill is
// `accounting:sync-ledger --all`. Runs after monthly billing settles.
Schedule::command('accounting:sync-ledger')
    ->dailyAt('05:00')
    ->name('atriom-sync-ledger')
    ->withoutOverlapping();

// Weekly FULL backfill (defense-in-depth). The daily run only sweeps the recent
// 2-day updated_at window, so any document whose money-affecting input changed
// WITHOUT bumping its own updated_at — a re-typed invoice item, a re-homed
// warehouse/bill, an edit stranded by a closed period — would sit stale until a
// manual `--all`. A weekly full reconcile self-heals those within 7 days. Runs
// Friday (lowest-traffic day) ahead of the daily window run.
Schedule::command('accounting:sync-ledger --all --scheduled')
    ->weeklyOn(5, '03:00')
    ->name('atriom-sync-ledger-full')
    ->withoutOverlapping();
