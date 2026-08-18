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

    /**
     * Whether to compute the accruing END-OF-SERVICE GRATUITY liability (مكافأة نهاية الخدمة).
     *
     * **OFF by default, and that is a considered position rather than caution.** Egyptian Labour
     * Law 12/2003 Art. 122 gives a gratuity of half a month's pay per year for the first five years
     * and a month's pay per year thereafter — but it applies to workers **not covered by the social
     * insurance law**, and in Egypt most employees are covered. So unlike the Gulf, an Egyptian
     * employer often owes no gratuity at all, and accruing a provision nobody owes overstates the
     * liability exactly as surely as omitting a real one understates it.
     *
     * Whether this workforce is entitled is a question about their contracts and their insurance
     * status, which is the accountant's to answer and not the software's to assume. Same treatment
     * as straight-line rent under EAS 49: built, correct, and switched off until someone decides.
     */
    public bool $gratuity_enabled = false;

    /** Days of pay accrued per year of service, for the FIRST five years (Art. 122: half a month). */
    public float $gratuity_days_first_five = 15.0;

    /** Days of pay accrued per year of service AFTER five years (Art. 122: one month). */
    public float $gratuity_days_thereafter = 30.0;

    public static function group(): string
    {
        return 'payroll';
    }
}
