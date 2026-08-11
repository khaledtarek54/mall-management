<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Replaces the static config/billing.php values. Admins can change these
 * at runtime via /admin/settings → Billing tab without touching .env or
 * editing config files.
 */
class BillingSettings extends Settings
{
    // PHP-level defaults so the class is usable even before the settings
    // migration has run (e.g. fresh clone, CI before seed, deploy ordering).
    // The DB row, when present, overrides these.
    public float $late_fee_percent = 2.0;
    public int $late_fee_grace_days = 7;
    public float $late_fee_minimum = 50.00;

    /**
     * Flat fee charged when a post-dated cheque is returned unpaid (Yardi posts an NSF charge).
     *
     * 0 = OFF, and that is how it ships: a fee appearing on invoices after an upgrade would be a
     * surprise to the operator and the tenant alike. Billed by an explicit operator action, never
     * automatically on bounce — the same separation module 31 draws between recording a violation
     * and billing its fine.
     */
    public float $nsf_fee_amount = 0.0;

    /**
     * Apply a tenant's on-account credit to a new invoice automatically (Voyager behaviour).
     *
     * Yardi applies open credit to the next charge without being asked, and this follows it. It is
     * a SETTING rather than a hard rule because the case against is real: a credit raised in dispute,
     * or one the tenant expects refunded in cash, silently disappearing into next month's rent is a
     * support call. Voyager makes it configurable for the same reason — so the operator can turn it
     * off for a property that refunds rather than offsets.
     *
     * Applying still goes through `ApplyTenantCreditService`, which posts its own dated
     * Dr Unearned / Cr AR document. Nothing about the accounting changes; only who triggers it.
     */
    public bool $auto_apply_tenant_credit = true;

    public int $monthly_billing_day = 1;
    public string $monthly_billing_time = '02:00';

    /**
     * The multiple of last rent a held-over tenant pays, as a percentage. 150% is the standard
     * Egyptian commercial default and is a deterrent by design: holdover should cost more than
     * renewing. Per-lease terms override it — this is only what the conversion form proposes.
     */
    public float $holdover_default_rate_pct = 150.0;

    /**
     * Recognise rent on a STRAIGHT-LINE basis over the lease term (story RA-02, EAS 49 / IFRS 16).
     *
     * **Ships OFF.** Enabling it changes what the P&L says about every stepped or abated lease —
     * revenue is recognised evenly while billing stays on the contracted ladder — and that is the
     * accountant's ruling, made against a before/after they can read. It changes NOTHING about what
     * a tenant is invoiced, and the tests prove invoices are byte-identical either way.
     */
    public bool $straight_line_rent_enabled = false;

    public int $cam_reconciliation_month = 1;
    public int $cam_reconciliation_day = 15;
    public string $cam_reconciliation_time = '03:00';

    public static function group(): string
    {
        return 'billing';
    }
}
