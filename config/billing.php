<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Late-fee policy
    |--------------------------------------------------------------------------
    |
    | grace_days: number of calendar days after due_date before a late fee is
    | added. The fee is calculated as max(min_amount, balance * percent / 100)
    | and applied once per invoice (idempotent — re-runs are a no-op).
    */

    'late_fee_percent' => env('LATE_FEE_PERCENT', 2.0),
    'late_fee_grace_days' => env('LATE_FEE_GRACE_DAYS', 7),
    'late_fee_minimum' => env('LATE_FEE_MINIMUM', 50.00),

    /*
    |--------------------------------------------------------------------------
    | Monthly billing schedule
    |--------------------------------------------------------------------------
    | Day of month and time (24h, app TZ) to auto-run monthly billing.
    */
    'monthly_billing_day' => env('MONTHLY_BILLING_DAY', 1),
    'monthly_billing_time' => env('MONTHLY_BILLING_TIME', '02:00'),

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
];
