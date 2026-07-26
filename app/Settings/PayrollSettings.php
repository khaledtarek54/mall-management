<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Egyptian payroll policy the operator's accountant owns — the statutory withholdings
 * used to PRE-FILL a payroll run's per-employee lines when it is generated from the
 * roster (GeneratePayrollService). Configured rather than compiled in for the same
 * reason as TaxSettings: a guessed constant would look authoritative and be wrong.
 *
 * Both rates ship at 0 (a generated line then carries gross = base salary with the
 * deductions left for the operator to enter). Egyptian income tax (ضريبة كسب العمل)
 * is genuinely progressive-bracketed, and social insurance (التأمينات) is a share of a
 * capped subscription salary — so these flat rates are a *starting point* the accountant
 * sets, and every generated amount stays editable per line before the run is approved.
 */
class PayrollSettings extends Settings
{
    /** Employee social-insurance share, % of gross, applied when generating lines. */
    public float $social_insurance_rate = 0.0;

    /** Salary (income) tax, % of gross — a flat convenience default for generation. */
    public float $salary_tax_rate = 0.0;

    /**
     * EMPLOYER social-insurance contribution, % of gross. Unlike the employee share above,
     * this is a company cost that does NOT reduce net pay — it books a Social Insurance
     * Expense + adds to the liability owed to the authority. 0 by default (accountant sets it).
     */
    public float $employer_social_insurance_rate = 0.0;

    public static function group(): string
    {
        return 'payroll';
    }
}
