<?php

use App\Jobs\ApplyLateFees;
use App\Jobs\RunMonthlyBilling;
use App\Support\Health;
use App\Support\ScheduleSetting;
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
        (int) ScheduleSetting::billing('monthly_billing_day', 'billing.monthly_billing_day', 1),
        (string) ScheduleSetting::billing('monthly_billing_time', 'billing.monthly_billing_time', '02:00'),
    )
    ->name('atriom-monthly-billing')
    ->withoutOverlapping();

// The OWNER side of the same night (module 37). A unit owner pays a monthly صيانة assessment on his
// ownership exactly as a tenant pays a service charge on his lease — but an ownership carries none of
// a lease's rules, so it is a separate service, a separate cache lock and a separate run.
//
// Runs 30 minutes after the lease run rather than beside it: the two bill disjoint agreements and
// could safely overlap, but staggering keeps one heavy write window instead of two competing ones,
// and puts the assessment failures in their own log line.
//
// This entry did not exist until 2026-08-18. `BillUnitOwnershipsService` shipped with module 37 in
// August 2026 and was never wired to anything — its own docblock spoke of "the scheduled one" while
// no schedule ever called it, so every handed-over owner went un-billed in production.
Schedule::command('billing:run-assessments')
    ->monthlyOn(
        (int) ScheduleSetting::billing('monthly_billing_day', 'billing.monthly_billing_day', 1),
        // The DAY is the operator's billing-day setting — both runs bill the same night. The TIME is
        // config-only and deliberately not a settings property: it is a stagger, not a policy, and a
        // settings field with no screen behind it reads as configurable when it is not.
        (string) config('billing.assessment_billing_time', '02:30'),
    )
    ->name('atriom-owner-assessments')
    ->withoutOverlapping();

// 04:00, before the 05:00 ledger sweep. That ordering used to be LOAD-BEARING and no longer is:
// a late fee mutated a posted invoice's total through recomputeTotals(), which saves quietly and so
// never fired the real-time GL hook, leaving AR and the GL disagreeing by the fee until the sweep.
// Since 2026-08-11 (FS-27) the fee is its OWN invoice — dated when it was incurred, so April's
// penalty stops being January revenue — and an ordinary Invoice::create() fires the hook like any
// other document. The fee now posts within seconds of being charged.
//
// Kept at 04:00 anyway: it is a sensible slot and the sweep still backstops everything. Just do not
// cite late fees as the reason the ordering matters.
Schedule::job(new ApplyLateFees)
    ->dailyAt('04:00')
    ->name('atriom-late-fees')
    ->withoutOverlapping();

// Straight-line rent recognition (RA-02). Scheduled from day one and a NO-OP until the accountant
// enables it in Settings → Billing — so switching it on needs a click, not a deploy. Runs on the
// 2nd so the month it recognises has closed and its invoices exist.
Schedule::command('accounting:post-straight-line-rent')
    ->monthlyOn(2, '04:00')
    ->name('atriom-straight-line-rent')
    ->withoutOverlapping();

Schedule::command('cam:reconcile')
    ->yearlyOn(
        (int) ScheduleSetting::billing('cam_reconciliation_month', 'billing.cam_reconciliation_month', 1),
        (int) ScheduleSetting::billing('cam_reconciliation_day', 'billing.cam_reconciliation_day', 15),
        (string) ScheduleSetting::billing('cam_reconciliation_time', 'billing.cam_reconciliation_time', '03:00'),
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

// Deliver the saved reports that are due today (RP-04). The month-end pack used to be six screens
// exported by hand on a day somebody had to remember, which meant it arrived late in the months
// somebody was on leave and not at all in the months somebody left.
//
// Early, so a monthly pack is in the inbox before the day starts. The command is idempotent —
// `last_delivered_on` is claimed under a lock and re-checked inside the transaction — so a
// catch-up run after downtime re-sends nothing.
Schedule::command('reports:deliver')
    ->dailyAt('06:00')
    ->name('atriom-deliver-scheduled-reports')
    ->withoutOverlapping();

// Shopper feed housekeeping (module 36). Posts past their display window are archived so
// "published" in the operator's register means "running". The read-side predicate already hides
// them from shoppers — this keeps the LIST honest. Hourly rather than daily because an offer
// ending at noon should leave the marketing team's live list around noon, not at 2am tomorrow.
Schedule::command('marketing:expire-posts')
    ->hourly()
    ->name('atriom-expire-marketing-posts')
    ->withoutOverlapping();

// Mall news (module 27). Broadcasts notices whose scheduled time has arrived. Every fifteen
// minutes rather than hourly: the operator picks a wall-clock time in the form, and a notice
// scheduled for 09:00 that lands at 09:58 is a notice the operator will stop trusting the
// scheduler for. The sweep is idempotent and re-checks each row under a lock, so a short interval
// costs a query and nothing else.
Schedule::command('announcements:send-scheduled')
    ->everyFifteenMinutes()
    ->name('atriom-send-scheduled-announcements')
    ->withoutOverlapping();

// Chase vendor compliance documents 30 days out and again on lapse. The dispatch gate
// already drops a vendor with lapsed insurance from every assignment picker — without
// this the operator gets no warning, just a contractor missing from a dropdown. The
// statutory documents (tax card, commercial register, social insurance) are chased too.
Schedule::command('vendors:scan-document-expiry')
    ->dailyAt('02:40')
    ->name('atriom-scan-vendor-document-expiry')
    ->withoutOverlapping();

// The same chase for TENANT paperwork — above all the insurance certificate the lease obliges the
// retailer to carry. Sharper than the vendor case rather than softer: an uninsured contractor is at
// least stopped at the dispatch gate, whereas an uninsured retailer simply keeps trading, so this
// alert is the entire mechanism rather than a courtesy on top of one.
Schedule::command('tenants:scan-document-expiry')
    ->dailyAt('02:45')
    ->name('atriom-scan-tenant-document-expiry')
    ->withoutOverlapping();

// Alert on contracts that have reached their NOTICE deadline (end_date − notice_period_days).
// expire-contracts above fires on the end date, which is far too late to decide anything: miss
// the notice window and the contract auto-renews at the old rate, or the mall opens with no
// contractor. Idempotent via renewal_alert_for; re-signing re-arms it.
Schedule::command('vendors:scan-contract-renewals')
    ->dailyAt('02:45')
    ->name('atriom-scan-contract-renewals')
    ->withoutOverlapping();

// Monthly housekeeping. Spatie's activitylog:clean drops rows older than
// the config's clean_after_days (default 365) so the audit log doesn't
// accumulate indefinitely (audit M20 F-75 / D-59).
Schedule::command('activitylog:clean')
    ->monthlyOn(1, '05:00')
    ->name('atriom-clean-activity-log')
    ->withoutOverlapping();

// Daily auto-close pass on resolved maintenance requests older than
// config('requests.auto_close_after_days') (default 7). Without this
// resolved tickets accumulate forever — operators occasionally need the
// "open" filter to actually mean "current work" (audit M09 F-38 / D-30).
Schedule::command('requests:auto-close')
    ->dailyAt('03:00')
    ->name('atriom-auto-close-requests')
    ->withoutOverlapping();

// Daily scan raising preventive-maintenance work orders for plans that are due.
// Idempotent + lock-safe (advances next_due_date), so a re-run is harmless.
Schedule::command('facility:generate-preventive')
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
Schedule::command('facility:scan-sla-breaches')
    ->hourly()
    ->name('atriom-scan-wo-sla-breaches')
    ->withoutOverlapping();

// Daily scan for overdue (late-paid) invoices. Alerts the property's Jawad
// owners via the bell. Idempotent through owner_overdue_notified_at.
Schedule::command('billing:scan-overdue-invoices')
    ->dailyAt('06:00')
    ->name('atriom-scan-overdue-invoices')
    ->withoutOverlapping();

// Remind percentage-rent tenants who haven't submitted last month's sales declaration — else
// their overage never bills (a silent revenue leak). Runs on the 10th (tenants have the first
// week+ to report the closed month). Idempotent: one reminder per (lease, period).
Schedule::command('sales:scan-missing-declarations')
    ->monthlyOn(10, '08:00')
    ->name('atriom-scan-missing-sales-declarations')
    ->withoutOverlapping();

// …and, for a tenant who still has not filed, raise an ESTIMATE so silence stops being a costless
// way to avoid percentage rent. Runs a week after the chase, so the tenant has had the reminder
// and a chance to file first.
Schedule::command('sales:estimate-missing')
    ->monthlyOn(8, '07:30')
    ->name('atriom-estimate-missing-sales')
    ->withoutOverlapping();

// FR-INV-03 — each mall's own shortages, once per shortage rather than once per run.
// Daily, not hourly: a reorder level is a restocking hint, not a deadline, and an alert that
// repeats faster than anyone can act on it is an alert people learn to ignore.
Schedule::command('inventory:scan-low-stock')->dailyAt('07:30');

// Expire leases whose term has run out, and re-project any unit whose occupancy went stale with
// them (F-04 / F-05). Runs BEFORE the escalation sweep at 05:30, deliberately: an ended lease that
// this moves to `expired` is then out of scope for the escalation run in the same night rather than
// the next one. The escalation query carries its own term guard as well — this ordering is a
// convenience, not the thing that makes it correct.
//
// 05:15 rather than the small hours: it re-projects every unit, so it belongs beside the other
// portfolio sweeps rather than competing with the 02:00 billing window.
Schedule::command('leases:expire')
    ->dailyAt('05:15')
    ->name('atriom-expire-leases')
    ->withoutOverlapping();

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

// The other half of the cheque question, and the one nothing could see: which tenants are about
// to RUN OUT of lodged cheques while their lease still has term to run. Egyptian practice is a
// year of cheques lodged against a longer lease, so running dry mid-term is the normal shape of
// the arrangement — and it is invisible, because every cheque in the register clears on time
// right up until the month the money simply stops. Weekly, not daily: the answer moves when a
// batch is lodged, not overnight.
// Permits to work whose window has passed with no closure recorded — the safety finding. HOURLY,
// not daily: a permit is bounded to the hour, so a daily sweep could leave hazardous work
// unaccounted for most of a day. Reports a state; writes nothing to the permit.
Schedule::command('facility:scan-open-permits')
    ->hourly()
    ->name('atriom-scan-open-work-permits')
    ->withoutOverlapping();

Schedule::command('pdc:scan-coverage')
    ->weeklyOn(1, '08:00')
    ->name('atriom-scan-cheque-coverage')
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

// Lease-option notice windows. Runs BEFORE the expiry reminder above in the day's order for a
// reason worth stating: the expiry reminder speaks 90 days before the lease ends, by which point a
// typical notice window ("no later than 9 months before expiry") has been shut for months. This is
// the alert that arrives while the leasing team can still act.
Schedule::command('leases:scan-option-windows')
    ->dailyAt('06:45')
    ->name('atriom-scan-lease-option-windows')
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
// `accounting:sync-ledger --all`. Runs after monthly billing settles. Keep this LAST of the money
// jobs: everything upstream relies on it to reconcile what it changed. (It used to also be the only
// thing that posted late fees; that stopped being true when the fee became its own invoice — see
// the note on the 04:00 job.)
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

// The independent re-derivation of the AR books — and until 2026-08-12 it was never scheduled at
// all. It existed, it worked, and the only thing that ran it was a diligent operator opening the
// month-end checklist. A control nobody runs is not a control.
//
// Read-only, so it is safe to run unattended, and it goes AFTER the weekly full sweep: reconciling
// before the sweep has had its chance to self-heal would report drift the next hour would fix.
// The tie-out that the sweep records on every run is the cheap daily signal; this is the deep one
// that says WHICH document disagrees.
Schedule::command('billing:reconcile --deep')
    ->weeklyOn(5, '04:00')
    ->name('atriom-books-reconcile')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| On the SCHEDULER rather than a CI/deploy workflow: a backup is a runtime
| concern, not a build one — it has to keep running between deploys, and on a
| box CI never touches. The scheduler already keeps billing and the GL current,
| so a backup rides on infrastructure whose failure is independently visible.
|
| Order matters. Clean first so retention frees space before the new archive is
| written (a full disk is how a backup run fails), then back up, then verify.
*/
Schedule::command('backup:clean')
    ->dailyAt('01:00')
    ->name('atriom-backup-clean')
    ->withoutOverlapping();

Schedule::command('backup:run')
    ->dailyAt('01:15')
    ->name('atriom-backup-run')
    ->withoutOverlapping();

// The check that actually matters. `backup:run` failing is loud, but a backup
// job that silently STOPPED running is not — and that is the state you discover
// on the day you need to restore. The monitor fails when the newest archive is
// older than a day, on every configured destination, which detects both.
Schedule::command('backup:monitor')
    ->dailyAt('07:30')
    ->name('atriom-backup-monitor')
    ->withoutOverlapping();

/*
 * The restore drill. backup:monitor checks an archive EXISTS and is recent; this one checks it can
 * actually be restored — it opens the newest archive, replays its dump into a scratch database and
 * confirms the tables the business cannot lose are there.
 *
 * Every way a backup dies looks identical to a healthy one from outside the file: a password nobody
 * kept, a dump truncated when the disk filled, or no `mysqldump` on the box so the archive holds
 * the uploads and no database at all (observed on this project — backup:run exits 127). monitor
 * reports all three as healthy.
 *
 * Weekly, not nightly: it restores the whole database, so it is the most expensive scheduled job
 * here. Sunday 03:00 — after the nightly backup, well clear of the billing window.
 */
Schedule::command('atriom:backup-verify')
    ->weeklyOn(0, '03:00')
    ->name('atriom-backup-verify')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Scheduler heartbeat
|--------------------------------------------------------------------------
|
| Stamps a file every minute so /health can tell whether cron is alive.
|
| This is the one check nothing else can make. Every scheduled monitor —
| backup:monitor included — can only report a problem while the scheduler is
| running, so none of them can report that the scheduler has STOPPED. A dead
| cron silences the billing run, the GL sync, the nightly backup and every
| alarm that would have told you, all at once, and looks exactly like a quiet
| night.
|
| Writes a FILE, never the database or cache: both are database-backed here, so
| a DB outage would otherwise also report "scheduler dead" and bury the real
| fault under a second, wrong alarm.
*/
Schedule::call(fn () => Health::stampHeartbeat())
    ->everyMinute()
    ->name('atriom-scheduler-heartbeat')
    ->withoutOverlapping();
