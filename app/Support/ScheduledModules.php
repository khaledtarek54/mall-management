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

        'atriom:prune-activity-log' => 'activity_log',
    ];

    /**
     * Commands that run whatever is switched off, each with the reason.
     *
     * "Core" is not a shrug. Billing, leasing, the ledger and the backups are what the system IS;
     * a mall that has turned off invoicing has turned off Atriom. The reasons are here so that
     * moving a command out of this list is a decision somebody argued rather than a default.
     *
     * @var array<string, string>
     */
    public const CORE = [
        'accounting:post-straight-line-rent' => 'The general ledger. A lease that has commenced accrues rent whether or not any optional module is on.',
        'accounting:sync-ledger' => 'The general ledger. Its whole job is to notice documents the real-time hooks missed; gating it on anything would make the books depend on a toggle.',
        'announcements:send-scheduled' => 'An announcement already scheduled by an operator must go out. Withholding a message somebody composed and timed is a different act from disabling the screen that composes them.',
        'atriom:backup-verify' => 'The restore drill. Nothing about a disabled module makes an unverified backup safer.',
        'backup:clean' => 'Backups are infrastructure, not a feature — pruning old archives keeps the restore drill affordable and the disk from filling.',
        'backup:monitor' => 'Backups are infrastructure, not a feature. This is the one thing that notices an archive stopped being written at all.',
        'backup:run' => 'Backups are infrastructure, not a feature. A mall that has switched a module off still needs last night\'s data.',
        'expenses:generate-recurring' => 'The costs that arrive on a calendar rather than on an invoice — real-estate tax, municipal levies, a licence renewal. There is no `expenses` module key because money going OUT is not optional for a property manager, and a statutory cost that stopped booking because somebody switched a feature off would be discovered by the tax authority rather than by the operator.',
        'marketing:ensure-budgets' => 'The marketing budget is part of the money model — a levy is billed to tenants against it — and there is no `marketing` module key. Only the shopper-facing feed (module 36) is toggleable.',
        'billing:reconcile' => 'The weekly tie-out that says WHICH document the books disagree about. Gating a reconciliation on a feature flag is how a discrepancy goes unreported.',
        'billing:remind-overdue-tenants' => 'Billing is core — a mall that cannot invoice is not running Atriom. There is no `billing` key and there should not be one.',
        'billing:run-assessments' => 'The monthly صيانة run for unit owners (module 37). Core billing; owners are billed whatever else is off.',
        'billing:scan-overdue-invoices' => 'Core billing. An invoice goes overdue on its own; refusing to notice is not a configuration option.',
        'leases:apply-escalations' => 'Leasing is core. A contracted escalation is a term of an agreement, not a feature.',
        'leases:expire' => 'Leasing is core — and this one also re-projects unit occupancy, so skipping it leaves shops un-relettable.',
        'leases:remind-expiring' => 'Leasing is core. A term ending is a date in a signed contract, and the reminder is what gives anyone time to renew it.',
        'leases:scan-option-windows' => 'Leasing is core. An option window closes on a date in a signed contract.',
        'pdc:scan-coverage' => 'Post-dated cheques are how Egyptian tenants pay; there is no toggle for them.',
        'pdc:scan-maturing' => 'Post-dated cheques are how Egyptian tenants pay; there is no toggle for them.',
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
