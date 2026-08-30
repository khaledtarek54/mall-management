<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Feature\Scenarios\ScheduledModuleGateConformanceTest;

/**
 * Which module each scheduled command belongs to — so turning a module off actually stops its work.
 *
 * ## The problem this exists for
 *
 * `Modules::enabled()` gated navigation, resources and actions, and **one** of the thirty-four
 * scheduled commands (`inventory:scan-low-stock`, which checks the flag in its own `handle()`). The
 * other thirty-three ran regardless. Disable `facility` and the nightly generator kept raising
 * preventive work orders, the hourly scan kept alerting staff about SLA breaches on jobs, and the
 * permit scan kept reporting — all for a module whose screens nobody could open. The operator turned
 * something off and it kept working, which is worse than it never having had a switch.
 *
 * ## Why the guard is applied HERE and not in each command
 *
 * Thirty-three edits is thirty-three chances to forget, and the thirty-fourth command added next
 * month inherits nothing. {@see guard()} walks the schedule after it is fully defined and attaches
 * the skip to every event from this one registry, so a new command is covered by being scheduled at
 * all — and {@see ScheduledModuleGateConformanceTest} fails the build if it
 * is not classified.
 *
 * Skipping, not removing: a skipped event still appears in `schedule:list` with its reason, so an
 * operator wondering why the PM run stopped can see that it is disabled rather than broken.
 */
final class ScheduledModules
{
    /**
     * artisan command => the `Modules::KEYS` entry that owns it.
     *
     * A command whose module is turned off does not run. Everything genuinely core is in
     * {@see CORE} instead, with the reason it can never be switched off.
     *
     * @var array<string, string>
     */
    public const OWNED_BY = [
        'cam:reconcile' => 'cam',

        'facility:generate-preventive' => 'facility',
        'facility:scan-open-permits' => 'facility',
        'facility:scan-sla-breaches' => 'facility',

        'inventory:scan-low-stock' => 'inventory',

        'requests:auto-close' => 'requests',
        'requests:scan-sla-breaches' => 'requests',

        'sales:estimate-missing' => 'tenant_sales',
        'sales:scan-missing-declarations' => 'tenant_sales',

        'vendors:expire-contracts' => 'vendors',
        'vendors:scan-contract-renewals' => 'vendors',
        'vendors:scan-document-expiry' => 'vendors',

        'accounting:post-depreciation' => 'fixed_assets',

        // NOT `marketing` — there is no such key, and `Modules::enabled()` returns true for
        // anything unlisted, so mapping to it would have created exactly the phantom this change
        // exists to remove. The marketing BUDGET is core; only the shopper-facing FEED is toggleable.
        'marketing:expire-posts' => 'marketing_posts',

        'reports:deliver' => 'reports',

        'announcements:send-scheduled' => 'announcements',

        'expenses:generate-recurring' => 'recurring_expenses',

        'marketing:ensure-budgets' => 'marketing',

        'billing:run-assessments' => 'unit_ownerships',

        'pdc:scan-coverage' => 'post_dated_cheques',
        'pdc:scan-maturing' => 'post_dated_cheques',

        'atriom:prune-activity-log' => 'activity_log',
    ];

    /**
     * Commands that run whatever is switched off, each with the reason.
     *
     * "Core" is not a shrug. Billing, leasing, the ledger and the backups are what the system IS;
     * a mall that has turned off invoicing has turned off Atriom. The reasons are here so that
     * moving a command out of this list is a decision somebody argued rather than a default.
     *
     * **Six moved out on 2026-08-23**, when `Modules::KEYS` grew from sixteen entries to
     * thirty-four and most of the system stopped being core by omission. Each had a reason written
     * here, and each reason turned out to be an argument for the module EXISTING rather than for
     * its scheduled work outliving the operator's decision to switch it off:
     *
     *  - `announcements:send-scheduled` argued that a message somebody composed and timed must go
     *    out. It must — while the module is on. With Mall News switched off the operator cannot see
     *    the queue, cannot cancel a post, and cannot explain the one that arrived anyway.
     *  - `expenses:generate-recurring` argued that a statutory cost silently ceasing to book would
     *    be discovered by the tax authority. True, and the answer is that switching Recurring costs
     *    off is a deliberate act with a visible switch, not a silence — `schedule:list` still shows
     *    the event with its skip reason.
     *  - `marketing:ensure-budgets` and `pdc:scan-{coverage,maturing}` each said in writing "there
     *    is no module key for this". There is now, and a reason that names the absence of a key is
     *    exactly the reason that expires when the key arrives.
     *  - `billing:run-assessments` bills unit owners, which is module 37 and now has its own key.
     *
     * @var array<string, string>
     */
    public const CORE = [
        'atriom:notify-status' => 'Operational alerting. It reports on the box itself — database, cache, queue, scheduler, backups, extensions — none of which belongs to a module, and a module being switched off must not make the box stop reporting that it is unwell. It is silent unless DISCORD_WEBHOOK_URL is set, which is the real off switch.',
        'atriom:prune-transient-data' => 'Housekeeping for what the SYSTEM generates — notifications, export files, import failures, failed jobs, expired tokens. It belongs to no module because every module produces some of it, and switching it off would only mean the tables grow silently. Each period is 0-able on the Settings screen, which is the real off switch.',
        'accounting:post-straight-line-rent' => 'The general ledger. A lease that has commenced accrues rent whether or not any optional module is on.',
        'accounting:sync-ledger' => 'The general ledger. Its whole job is to notice documents the real-time hooks missed; gating it on anything would make the books depend on a toggle.',
        'atriom:backup-verify' => 'The restore drill. Nothing about a disabled module makes an unverified backup safer.',
        'backup:clean' => 'Backups are infrastructure, not a feature — pruning old archives keeps the restore drill affordable and the disk from filling.',
        'backup:monitor' => 'Backups are infrastructure, not a feature. This is the one thing that notices an archive stopped being written at all.',
        'backup:run' => 'Backups are infrastructure, not a feature. A mall that has switched a module off still needs last night\'s data.',
        'billing:reconcile' => 'The weekly tie-out that says WHICH document the books disagree about. Gating a reconciliation on a feature flag is how a discrepancy goes unreported.',
        'billing:remind-overdue-tenants' => 'Billing is core — a mall that cannot invoice is not running Atriom. There is no `billing` key and there should not be one.',
        'billing:scan-overdue-invoices' => 'Core billing. An invoice goes overdue on its own; refusing to notice is not a configuration option.',
        'leases:apply-escalations' => 'Leasing is core. A contracted escalation is a term of an agreement, not a feature.',
        'leases:expire' => 'Leasing is core — and this one also re-projects unit occupancy, so skipping it leaves shops un-relettable.',
        'leases:remind-expiring' => 'Leasing is core. A term ending is a date in a signed contract, and the reminder is what gives anyone time to renew it.',
        'leases:scan-option-windows' => 'Leasing is core. An option window closes on a date in a signed contract.',
        'tenants:scan-document-expiry' => 'A tenant\'s tax card or commercial register expiring is a compliance fact about a counterparty, not a module.',
    ];

    /**
     * Attach the module guard to every event on the schedule.
     *
     * Called once, at the END of `routes/console.php`, after everything is defined. Matching is on
     * the artisan command name parsed out of the event's command line, because Laravel stores the
     * full invocation (`'/path/php' 'artisan' name --flags`).
     */
    public static function guard(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            $name = self::commandName($event);

            if ($name === null) {
                continue;
            }

            $module = self::OWNED_BY[$name] ?? null;

            if ($module === null) {
                continue;
            }

            // A closure, not a boolean: the schedule is built once per process and a module can be
            // switched off between runs. Evaluating now would freeze the answer at boot.
            $event->skip(fn (): bool => ! Modules::enabled($module));
        }
    }

    /** The artisan command name, or null for a closure/exec event that is not one. */
    public static function commandName(Event $event): ?string
    {
        if (! is_string($event->command) || $event->command === '') {
            return null;
        }

        // "'…/php' 'artisan' facility:generate-preventive --flag" → facility:generate-preventive
        if (! preg_match("~'artisan'\\s+(?:'([^']+)'|(\\S+))~", $event->command, $m)) {
            return null;
        }

        return $m[1] !== '' ? $m[1] : ($m[2] ?? null);
    }
}
