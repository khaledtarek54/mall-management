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
    public float $late_fee_percent;
    public int $late_fee_grace_days;
    public float $late_fee_minimum;

    public int $monthly_billing_day;
    public string $monthly_billing_time;

    public int $cam_reconciliation_month;
    public int $cam_reconciliation_day;
    public string $cam_reconciliation_time;

    public static function group(): string
    {
        return 'billing';
    }
}
