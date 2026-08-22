<?php

namespace App\Support;

use App\Models\PayrollRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

/**
 * What Egypt's statutory payroll numbers were on a given date (EG-03).
 *
 * The payroll twin of {@see Vat}: `PayrollRates::for($periodMonth)` resolves the rung in force for
 * the month being run, exactly as `Vat::rateForType($code, $on)` resolves for a document's date.
 *
 * ## The date is a parameter, not "now"
 *
 * `GeneratePayrollService` used to read three undated settings, so a January run generated in March
 * computed on March's numbers, a rise could not be entered in advance, and nothing recorded what a
 * past run had used. Egypt has raised the insurable-wage band on a January cadence for several years
 * running. **Pass the run's `period_month`** — the month the money is FOR, not the day someone
 * pressed Generate.
 *
 * ## Origination only
 *
 * An approved payroll freezes its own amounts on `payroll_lines`, so changing a rung re-rates
 * nothing that has been computed. Same rule as an issued invoice keeping the VAT rate it was billed
 * at.
 *
 * ## The floor, for a database whose ladder is not seeded
 *
 * {@see UNCAPPED} is the shipped fallback, and it is deliberately *no band and no rates* rather than
 * a plausible-looking set of numbers. Zero rates are what the install ships and what the settings
 * screen's help offers as a supported posture; and inventing an insurable-wage band for a period we
 * have no rung for would apply a cap the operator never entered. Both mean a database with no ladder
 * behaves precisely as this system did before the ladder existed.
 */
final class PayrollRates
{
    private const MEMO = 'atriom.payroll_rates.ladder';

    /**
     * The answer for a date no rung covers: no band, no rates.
     *
     * Not a guess at last year's figures. An under-applied deduction is visible on the payslip and
     * gets corrected; a cap applied from a number nobody entered is invisible and looks correct.
     */
    public const UNCAPPED = [
        'employee_social_insurance_rate' => 0.0,
        'employer_social_insurance_rate' => 0.0,
        'salary_tax_rate' => 0.0,
        'insurable_wage_floor' => null,
        'insurable_wage_ceiling' => null,
    ];

    private function __construct(
        public readonly float $employeeSocialInsuranceRate,
        public readonly float $employerSocialInsuranceRate,
        public readonly float $salaryTaxRate,
        public readonly ?float $insurableWageFloor,
        public readonly ?float $insurableWageCeiling,
    ) {}

    /** The rung in force on `$on` — the latest one starting on or before it. */
    public static function for(CarbonInterface|string|null $on = null): self
    {
        $date = $on === null
            ? CarbonImmutable::now()->toDateString()
            : CarbonImmutable::parse($on)->toDateString();

        $rung = self::UNCAPPED;

        foreach (self::ladder() as $row) {
            if ($row['effective_from'] > $date) {
                break;
            }

            $rung = $row;
        }

        return new self(
            employeeSocialInsuranceRate: (float) $rung['employee_social_insurance_rate'],
            employerSocialInsuranceRate: (float) $rung['employer_social_insurance_rate'],
            salaryTaxRate: (float) $rung['salary_tax_rate'],
            insurableWageFloor: $rung['insurable_wage_floor'] === null ? null : (float) $rung['insurable_wage_floor'],
            insurableWageCeiling: $rung['insurable_wage_ceiling'] === null ? null : (float) $rung['insurable_wage_ceiling'],
        );
    }

    /**
     * The wage social insurance is actually charged on — gross, clamped into the band.
     *
     * This is the whole of finding P-1. The service applied the rate to `base_salary` outright, so
     * every employee above the ceiling was over-deducted and the employer over-accrued. The cap is
     * on the WAGE, so it binds **both** shares — the employer-share line carried a comment saying
     * otherwise ("a company cost, so no cap needed"), which misreads the rule.
     *
     * A null bound is no bound. The floor is the one figure here that can INCREASE a deduction — an
     * employee earning below the minimum insurable wage is still insured on it — so an operator who
     * does not want it leaves the column empty rather than having to know a magic zero.
     */
    public function insurableWage(float $gross): float
    {
        $wage = max(0.0, $gross);

        if ($this->insurableWageFloor !== null) {
            $wage = max($wage, $this->insurableWageFloor);
        }

        if ($this->insurableWageCeiling !== null) {
            $wage = min($wage, $this->insurableWageCeiling);
        }

        return round($wage, 2);
    }

    /** Is any figure on this rung actually set? Used by the health check to tell nil from unconfigured. */
    public function isNil(): bool
    {
        return $this->employeeSocialInsuranceRate === 0.0
            && $this->employerSocialInsuranceRate === 0.0
            && $this->salaryTaxRate === 0.0;
    }

    /** Does this rung state an insurable-wage band at all? */
    public function hasBand(): bool
    {
        return $this->insurableWageFloor !== null || $this->insurableWageCeiling !== null;
    }

    /** Drop the per-request memo. Called from {@see PayrollRate}'s saved/deleted hooks. */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * Every rung, oldest first, as plain arrays.
     *
     * Memoised per REQUEST through the container rather than in a static: a `queue:work` daemon
     * outlives the request, and a static would hand a month-old ladder to every job it ran after
     * the accountant changed one.
     *
     * The `hasTable` guard is for the window every install passes through — `atriom:install` and the
     * test suite both generate payroll fixtures, and a resolver that fataled before its own
     * migration ran would make the migration unrunnable.
     *
     * @return list<array<string, mixed>>
     */
    private static function ladder(): array
    {
        if (app()->has(self::MEMO)) {
            return app(self::MEMO);
        }

        $ladder = Schema::hasTable('payroll_rates')
            ? PayrollRate::query()
                ->orderBy('effective_from')
                ->get()
                ->map(fn (PayrollRate $r): array => [
                    'effective_from' => $r->effective_from->toDateString(),
                    'employee_social_insurance_rate' => $r->employee_social_insurance_rate,
                    'employer_social_insurance_rate' => $r->employer_social_insurance_rate,
                    'salary_tax_rate' => $r->salary_tax_rate,
                    'insurable_wage_floor' => $r->insurable_wage_floor,
                    'insurable_wage_ceiling' => $r->insurable_wage_ceiling,
                ])
                ->all()
            : [];

        app()->instance(self::MEMO, $ladder);

        return $ladder;
    }
}
