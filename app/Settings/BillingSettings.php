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

    public int $monthly_billing_day = 1;
    public string $monthly_billing_time = '02:00';

    /**
     * The multiple of last rent a held-over tenant pays, as a percentage. 150% is the standard
     * Egyptian commercial default and is a deterrent by design: holdover should cost more than
     * renewing. Per-lease terms override it — this is only what the conversion form proposes.
     */
    public float $holdover_default_rate_pct = 150.0;

    public int $cam_reconciliation_month = 1;
    public int $cam_reconciliation_day = 15;
    public string $cam_reconciliation_time = '03:00';

    public static function group(): string
    {
        return 'billing';
    }
}
