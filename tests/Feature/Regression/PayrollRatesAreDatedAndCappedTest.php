<?php

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollRate;
use App\Services\GeneratePayrollService;
use App\Support\PayrollRates;
use Carbon\CarbonImmutable;

/**
 * EG-03, findings P-1 and P-3 — Egypt's statutory payroll numbers become dated, and gain the
 * insurable-wage band they never had.
 *
 * Two bugs, one shape. `GeneratePayrollService` read three flat settings **with no date argument**,
 * so a January run generated in March computed on March's numbers, a rise could not be entered in
 * advance, and nothing recorded what a past run had used — against a state that raises the
 * insurable-wage band every January. And it applied the social-insurance rate to `base_salary`
 * outright, with no floor and no ceiling, so every employee above the cap was over-deducted and the
 * employer over-accrued. The employer-share line even carried a comment saying no cap was needed
 * because it is a company cost, which misreads the rule: the cap is on the WAGE, and it binds both
 * shares.
 *
 * The correct shape was already in the codebase — `TaxCode::rateOn($code, $on)`, a rung with a start
 * date and no end date — and `PayrollRates::for()` is that shape for payroll.
 *
 * **What is deliberately NOT here: the bracket engine (P-2).** Egyptian income tax is a seven-band
 * progressive schedule with a personal exemption, and whether the operator wants this system to
 * compute statutory payroll at all is an open question on them (§6.4). `salary_tax_rate` stays a
 * flat rate on gross, and the ladder is what a bracket table will hang off when that is answered.
 */
function payrollRung(string $from, array $attrs = []): PayrollRate
{
    // `$attrs` FIRST. PHP's `+` keeps the LEFT operand's key on a collision, so defaults on the
    // left silently discard every argument — which is how the first cut of this file asserted a
    // 14,500 ceiling against a rung that had none.
    return PayrollRate::create($attrs + [
        'effective_from' => $from,
        'employee_social_insurance_rate' => 0,
        'employer_social_insurance_rate' => 0,
        'salary_tax_rate' => 0,
        'insurable_wage_floor' => null,
        'insurable_wage_ceiling' => null,
    ]);
}

/** A draft run for a month, plus one employee on that salary. */
function payrollFor(string $month, float $salary): Payroll
{
    $asset = makeAsset(['code' => 'MALL-'.substr($month, 0, 7).'-'.uniqid()]);

    // Built inline rather than through a shared helper: `makeRosterEmployee` is declared at file
    // scope in GeneratePayrollServiceTest, and a second declaration of it is a fatal at collection
    // that exits the whole suite with zero output.
    Employee::create([
        'asset_id' => $asset->id,
        'code' => 'E-'.uniqid(),
        'name' => 'Emp '.uniqid(),
        'hire_date' => '2025-01-01',
        'base_salary' => $salary,
        'payment_method' => 'bank',
    ]);

    return Payroll::create([
        'asset_id' => $asset->id,
        'period_month' => $month,
        'gross_salaries' => 0,
        'salary_tax' => 0,
        'social_insurance' => 0,
        'paid_from' => 'bank',
        'status' => 'draft',
    ]);
}

beforeEach(function () {
    // The migration seeds a rung; these tests state their own ladder, so start from nothing.
    PayrollRate::query()->delete();
    PayrollRates::flush();
});

it('answers with no band and no rates when the ladder is empty', function () {
    // The floor, and the safety case for shipping this: a database with no ladder behaves exactly
    // as the system did before the ladder existed. Never a guess at last year's figures — an
    // under-applied deduction shows on the payslip, a cap from a number nobody entered does not.
    $rates = PayrollRates::for('2026-03-01');

    expect($rates->employeeSocialInsuranceRate)->toBe(0.0)
        ->and($rates->insurableWageCeiling)->toBeNull()
        ->and($rates->hasBand())->toBeFalse()
        ->and($rates->insurableWage(999_999.0))->toBe(999_999.0);
});

it('resolves the rung in force for the date, not the latest one', function () {
    payrollRung('2025-01-01', ['insurable_wage_ceiling' => 14500, 'employee_social_insurance_rate' => 11]);
    payrollRung('2026-01-01', ['insurable_wage_ceiling' => 16700, 'employee_social_insurance_rate' => 11]);

    expect(PayrollRates::for('2025-06-01')->insurableWageCeiling)->toBe(14500.0)
        ->and(PayrollRates::for('2026-06-01')->insurableWageCeiling)->toBe(16700.0)
        // The day itself is the first day the new rung applies, not the last day of the old one.
        ->and(PayrollRates::for('2026-01-01')->insurableWageCeiling)->toBe(16700.0)
        // Before the ladder starts there is no rung, and that is not the earliest one.
        ->and(PayrollRates::for('2024-12-31')->insurableWageCeiling)->toBeNull();
});

it('clamps the insurable wage into the band, and treats a null bound as no bound', function () {
    payrollRung('2026-01-01', ['insurable_wage_floor' => 2700, 'insurable_wage_ceiling' => 16700]);
    $band = PayrollRates::for('2026-03-01');

    expect($band->insurableWage(50_000.0))->toBe(16700.0)   // above the ceiling
        ->and($band->insurableWage(1_000.0))->toBe(2700.0)  // below the floor — insured on it anyway
        ->and($band->insurableWage(9_000.0))->toBe(9000.0); // inside the band

    payrollRung('2027-01-01', ['insurable_wage_ceiling' => 20000]);
    $ceilingOnly = PayrollRates::for('2027-03-01');

    // A null floor is no floor. Not zero — zero would be a floor of nothing, which is the same
    // answer here but a different claim, and on the ceiling it would clamp every wage to 0.
    expect($ceilingOnly->insurableWage(1_000.0))->toBe(1000.0)
        ->and($ceilingOnly->insurableWage(50_000.0))->toBe(20000.0);
});

it('generates a run on the rates in force for the month it is FOR, not today', function () {
    payrollRung('2026-01-01', ['employee_social_insurance_rate' => 11]);
    payrollRung('2026-06-01', ['employee_social_insurance_rate' => 20]);

    // A January run, generated in September. Reading undated settings gave it September's numbers.
    CarbonImmutable::setTestNow('2026-09-15');
    $run = payrollFor('2026-01-01', 10_000);

    app(GeneratePayrollService::class)->generate($run);

    // 11% of January, not 20% of today.
    expect((float) $run->lines()->sole()->social_insurance)->toBe(1100.0);
});

it('charges social insurance on the capped wage and salary tax on the whole gross', function () {
    payrollRung('2026-01-01', [
        'insurable_wage_ceiling' => 16700,
        'employee_social_insurance_rate' => 11,
        'employer_social_insurance_rate' => 18.75,
        'salary_tax_rate' => 10,
    ]);

    CarbonImmutable::setTestNow('2026-03-05');
    $run = payrollFor('2026-03-01', 50_000);

    app(GeneratePayrollService::class)->generate($run);
    $line = $run->lines()->sole();

    // Two different bases, and getting them the same way round is the whole of finding P-1.
    expect((float) $line->social_insurance)->toBe(1837.0)            // 11% of 16,700, not of 50,000
        ->and((float) $line->employer_social_insurance)->toBe(3131.25) // the cap binds the employer too
        ->and((float) $line->salary_tax)->toBe(5000.0);              // 10% of the WHOLE gross
});

it('leaves an uncapped period uncapped', function () {
    // The control. A fix that started capping every historical period would be a different bug,
    // and the migration deliberately records no band before 1 Jan 2026 because we do not know one.
    payrollRung('2026-01-01', ['employee_social_insurance_rate' => 11]);

    CarbonImmutable::setTestNow('2026-03-05');
    $run = payrollFor('2026-03-01', 50_000);

    app(GeneratePayrollService::class)->generate($run);

    expect((float) $run->lines()->sole()->social_insurance)->toBe(5500.0);
});

it('does not re-rate a run that has already been generated', function () {
    payrollRung('2026-01-01', ['employee_social_insurance_rate' => 11]);

    CarbonImmutable::setTestNow('2026-03-05');
    $run = payrollFor('2026-03-01', 10_000);
    app(GeneratePayrollService::class)->generate($run);

    expect((float) $run->lines()->sole()->social_insurance)->toBe(1100.0);

    // The accountant corrects the rung afterwards. The amounts already computed are the run's own,
    // frozen on its lines — the same rule that keeps an issued invoice on the rate it was billed at.
    PayrollRate::query()->sole()->update(['employee_social_insurance_rate' => 25]);

    expect((float) $run->lines()->sole()->fresh()->social_insurance)->toBe(1100.0);
});

it('sees a rate the accountant just changed, in the same request', function () {
    // The ladder is memoised per request, and a write on the model fires no event on the resolver.
    // Without the flush hook a corrected rate would keep generating the old figure for the rest of
    // the request — and for the rest of the day on a `queue:work` daemon.
    payrollRung('2026-01-01', ['employee_social_insurance_rate' => 11]);

    expect(PayrollRates::for('2026-03-01')->employeeSocialInsuranceRate)->toBe(11.0);

    PayrollRate::query()->sole()->update(['employee_social_insurance_rate' => 14]);

    expect(PayrollRates::for('2026-03-01')->employeeSocialInsuranceRate)->toBe(14.0);
});
