<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Late-fee policy is NOT here
    |--------------------------------------------------------------------------
    |
    | Rate, grace and minimum resolve lease → property → portfolio through
    | Lease::lateFeeTerms() (ActsAsBillableAgreement). Three env-bound keys used
    | to sit here and were read by NOTHING; a deployer who set LATE_FEE_PERCENT
    | got silence. Deleted 2026-08-20 (EG-19 in docs/EGYPT-MARKET-FIT.md).
    |
    | The env vars are still live in ONE place: the settings migration
    | database/settings/2026_05_25_200000_create_billing_settings.php seeds the
    | initial row from them on a FRESH install. After that the value lives at
    | Settings → Billing, and per property at /admin/property-overrides.
    */

    /*
    |--------------------------------------------------------------------------
    | Monthly billing schedule
    |--------------------------------------------------------------------------
    | Day of month and time (24h, app TZ) to auto-run monthly billing.
    */
    'monthly_billing_day' => env('MONTHLY_BILLING_DAY', 1),
    'monthly_billing_time' => env('MONTHLY_BILLING_TIME', '02:00'),

    // The unit-OWNER assessment run (module 37) bills on the same day as the lease run, staggered
    // half an hour later so the two heavy write windows do not compete. See routes/console.php.
    'assessment_billing_time' => env('ASSESSMENT_BILLING_TIME', '02:30'),

    /*
    |--------------------------------------------------------------------------
    | CAM annual reconciliation schedule
    |--------------------------------------------------------------------------
    | When the previous year's CAM pools should be auto-reconciled. Defaults
    | to Jan 15 at 03:00. Allocations are generated but NOT auto-billed —
    | admin still reviews and clicks "Bill" per allocation.
    */
    'cam_reconciliation_month' => env('CAM_RECONCILIATION_MONTH', 1),
    'cam_reconciliation_day' => env('CAM_RECONCILIATION_DAY', 15),
    'cam_reconciliation_time' => env('CAM_RECONCILIATION_TIME', '03:00'),

    /*
    |--------------------------------------------------------------------------
    | Lease-expiry reminder window
    |--------------------------------------------------------------------------
    | How many days before a lease's expiry_date the tenant gets a renewal
    | reminder (leases:remind-expiring). Matches the admin "expiring soon"
    | widget window. Each lease reminds once (expiry_reminder_notified_at).
    */
    'lease_expiry_reminder_days' => env('LEASE_EXPIRY_REMINDER_DAYS', 90),

    /*
     * Days of warning before a lease-option notice window opens or closes. 30 by default: enough
     * to get a decision made, short enough that the alert still feels like news. The window itself
     * is on the option, not here — this is only the run-up.
     */
    'lease_option_notice_lead_days' => (int) env('LEASE_OPTION_NOTICE_LEAD_DAYS', 30),
];
