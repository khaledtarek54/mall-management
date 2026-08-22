<?php

namespace App\Support;

use App\Settings\ModulesSettings;

/**
 * Static helper around ModulesSettings — used everywhere a module needs
 * to ask "am I turned on?". Cached per-request via Laravel's container
 * (settings are loaded once when first requested).
 *
 *   if (! Modules::enabled('credit_notes')) {
 *       return false; // hide from nav, block route access, etc.
 *   }
 *
 * `KEYS` is the canonical list of toggleable module names. Anything not
 * in this list is treated as *core* and `enabled()` always returns true
 * for it.
 */
class Modules
{
    /** @var string[] */
    public const KEYS = [
        'credit_notes',
        'requests',
        'tenant_sales',
        'cam',
        'utility_meters',
        'vendors',
        'notes',
        'reports',
        'activity_log',
        'eta',
        'inventory',
        'fixed_assets',
        'employees',
        'custodies',
        'facility',
        'procurement',
        // The shopper-facing feed (module 36). Toggleable because it is the one module whose value
        // depends on something outside this system existing — a mall with no visitor app has
        // nothing to publish to, and should not be asked to review offers nobody will read.
        // Turning it off also 404s the public API (see the route group), not just the nav item.
        'marketing_posts',
    ];

    /**
     * Modules that are switched OFF at the code, not at the operator's discretion — unfinished work
     * that must not appear anywhere in a running system, with the reason it is parked.
     *
     * **This is stronger than the settings toggle and deliberately so.** `modules.eta` was already
     * defaulted false and a settings migration turned it off for existing installs, and ETA was
     * still *present*: an "ETA e-Invoicing" tab on the settings screen with two required fields, an
     * "ETA Status" column on every invoice list, "Submit invoices to the Egyptian Tax Authority" on
     * the roles matrix, ETA references on the invoice PDF, `eta_*` keys in the mobile API payload,
     * and a toggle inviting an operator to switch on a module that has never been certified. An
     * operator cannot tell "off" from "unfinished", and the toggle says the difference is theirs to
     * decide. It is not.
     *
     * **The key stays in {@see KEYS}.** A key outside that list is a guard that can never refuse —
     * `enabled()` returns true for anything unlisted — so removing `eta` here would turn every
     * `Modules::enabled('eta')` call site into a permanent *yes*, which is the exact opposite of
     * what freezing means. That mistake is silent: nothing errors, the module simply comes back on.
     *
     * **Why the settings row is not consulted at all.** A frozen module answers false whatever the
     * database says, so a stale row, a restored backup or a hand-edited `settings` table cannot put
     * an uncertified tax-authority integration back in front of an operator. It also means the one
     * place to look when the work resumes is this constant: delete the entry and every gated
     * surface returns intact.
     *
     * @var array<string, string> module key => why it is frozen
     */
    public const FROZEN = [
        'eta' => 'Module 16 (Egyptian Tax Authority e-invoicing) is incomplete and uncertified — mock '.
            'submissions only, no CAdES signing, no production credentials, and an issuer identity that '.
            'duplicates TaxSettings. Frozen 2026-08-22 at the owner\'s request so the work can be picked '.
            'up later; the services, job, config and tests are all kept, only the surfaces are gated. '.
            'Delete this entry to bring it back.',
    ];

    /**
     * Module keys an operator may actually toggle — everything except the frozen ones.
     *
     * The Settings screen builds its toggles from this, never from {@see KEYS}: a switch for a
     * module the code refuses to enable does nothing, and a control that does nothing is worse
     * than an absent one.
     *
     * @return string[]
     */
    public static function toggleable(): array
    {
        return array_values(array_diff(self::KEYS, array_keys(self::FROZEN)));
    }

    /** Is this module frozen in code, regardless of what the operator's settings say? */
    public static function frozen(string $module): bool
    {
        return array_key_exists($module, self::FROZEN);
    }

    public static function enabled(string $module): bool
    {
        if (self::frozen($module)) {
            return false;
        }

        if (! in_array($module, self::KEYS, true)) {
            // Core modules — always on.
            return true;
        }

        return (bool) (app(ModulesSettings::class)->{$module} ?? true);
    }
}
