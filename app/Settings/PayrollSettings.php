<?php

namespace App\Settings;

use App\Support\PayrollRates;
use Spatie\LaravelSettings\Settings;

/**
 * Egyptian payroll POLICY the operator's accountant owns.
 *
 * **The statutory RATES are no longer here** (EG-03, 2026-08-22). `social_insurance_rate`,
 * `employer_social_insurance_rate` and `salary_tax_rate` were undated scalars, so a January run
 * generated in March computed on March's numbers, a rise could not be entered in advance, and
 * nothing recorded what a past run had used — while Egypt raises the insurable-wage band every
 * January. They now live on `payroll_rates` as dated rungs, resolved through
 * {@see PayrollRates::for()} for the run's own `period_month`, which also carries the
 * insurable-wage floor and ceiling the flat rates had nowhere to put.
 *
 * The split is the same one that removed `TaxSettings::vat_standard_rate`: **settings hold policy,
 * master data holds rates.** What is left below is policy — whether this workforce is entitled to a
 * gratuity at all is a question about their contracts, not a figure the state publishes each year.
 */
class PayrollSettings extends Settings
{
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
