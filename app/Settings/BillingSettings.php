<?php

namespace App\Settings;

use App\Support\AgingBuckets;
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
    /**
     * Where the AR ageing buckets end, in days overdue — the operator's policy.
     *
     * 30/60/90 is the common shape and was hard-coded, which made "show me 45/90/120" a deploy. It
     * is a real request: a mall whose leases pay quarterly ages nothing meaningfully at 30 days,
     * and these are the first numbers an owner reads on the AR report.
     *
     * Read through {@see AgingBuckets}, which clamps a mistyped set back to the
     * default rather than throwing — an ageing report must not stop rendering because somebody put
     * the boundaries out of order.
     *
     * @var array<int, int>
     */
    public array $ar_aging_bucket_days = [30, 60, 90];

    /**
     * How many days a tenant has to pay, when their lease does not say.
     *
     * `leases.payment_terms_days` is the agreed figure and always wins. This is the fallback, and
     * it was the literal `7` repeated at twelve call sites — every service that raises an invoice,
     * plus the lease-creation default. Twelve places to edit and eleven chances to miss one, on a
     * number that decides when a receivable becomes overdue and therefore what the AR ageing says.
     */
    public int $default_payment_terms_days = 7;

    public float $late_fee_percent = 2.0;

    public int $late_fee_grace_days = 7;

    public float $late_fee_minimum = 50.00;

    /**
     * The most a single late fee may be, or 0 for no cap (EG-35, finding M-8).
     *
     * A percentage of an arrears has no upper bound, so a tenant six months behind on a large
     * invoice draws a penalty proportional to the debt rather than to the breach. Real clauses cap
     * it — *"2% per month, capped at EGP 5,000"* — and until now the cap was the one half of that
     * sentence the system could not express: `late_fee_minimum` existed and its opposite did not.
     *
     * 0 rather than null so the column keeps one type, and 0 reads correctly as "no ceiling" beside
     * a minimum of 50 that reads as "no floor" at 0. Per-property overridable, like the other three.
     */
    public float $late_fee_maximum = 0.0;

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
     * Months of rent the security deposit defaults to on a new lease (EG-35, finding M-11).
     *
     * The house policy was the literal `3` in `LeaseCreationService`'s `$rent * 3`, so *"three
     * months from Q1"* was a deploy and *"two months at the outlet mall"* was unsayable. The lease
     * still holds the agreed AMOUNT — this only proposes it, and a negotiated figure overrides it
     * exactly as it did before.
     *
     * Per-property overridable: deposit policy is negotiated per building against what the local
     * market will bear, which is the same reason `monthly_billing_day` is.
     */
    public float $default_security_deposit_months = 3.0;

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

    /**
     * The fallback payment terms, as a plain int.
     *
     * A static accessor rather than `app(BillingSettings::class)->…` at each call site: the twelve
     * places this replaced are inside billing services that run in loops, and reading it through
     * one method keeps the intent ("the default, because this lease does not say") legible at the
     * point of use.
     */
    public static function defaultPaymentTermsDays(): int
    {
        return max(0, (int) app(self::class)->default_payment_terms_days);
    }

    public static function group(): string
    {
        return 'billing';
    }
}
