<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollLine;
use DomainException;

/**
 * Payroll fixtures for the double-pay guards.
 *
 * **A class, not file-scope functions.** `PayrollHeaderHasToAgreeWithItsLinesTest` already declares a
 * `payrollRun()` of its own with a different signature, and two files declaring one name is a fatal
 * redeclaration during collection — the whole suite exits 255 with nothing on either stream, which
 * is exactly what happened here. Under `--parallel` it hides, because a worker only loads the files
 * it owns. `TestHelperUniquenessConformanceTest` is the gate that names both files.
 */
class PayrollRuns
{
    /** A sentinel meaning "the mall this fixture is about", distinct from an explicit NULL. */
    public const THIS_MALL = 'this-mall';

    private static int $seq = 0;

    /**
     * A run for `$month` with `$lines` payslip lines — 0 being a LUMP-SUM run, which names nobody.
     *
     * `$assetId` takes a sentinel rather than defaulting through `??`, because a consolidated run's
     * property is explicitly NULL and `?? $asset->id` collapses "no property" into "this mall".
     * That is not hypothetical: it made the consolidated-run case pass for the wrong reason — it was
     * really two mall runs, refused by the ordinary bar — and the property clause could be mutated
     * back to its broken form with the test still green.
     *
     * @param  list<Employee>  $employees  reuse the same people across two runs to drive the
     *                                     per-employee guard rather than the run-level one
     */
    public static function run(Asset $asset, int $lines, string $month = '2026-08-01', mixed $assetId = self::THIS_MALL, array $employees = []): Payroll
    {
        $run = Payroll::create([
            'asset_id' => $assetId === self::THIS_MALL ? $asset->id : $assetId,
            'period_month' => $month,
            'status' => 'draft',
            'gross_salaries' => 12000, 'allowances' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
            'advance_deductions' => 0, 'other_deductions' => 0, 'employer_social_insurance' => 0,
            'net_paid' => 12000, 'paid_from' => 'bank',
        ]);

        for ($i = 0; $i < $lines; $i++) {
            PayrollLine::create([
                'payroll_id' => $run->id,
                'employee_id' => ($employees[$i] ?? self::employee($asset))->id,
                'gross' => 4000, 'allowances' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
                'advance_deduction' => 0, 'other_deductions' => 0, 'employer_social_insurance' => 0,
            ]);
        }

        return $run->fresh();
    }

    public static function employee(Asset $asset): Employee
    {
        self::$seq++;

        return Employee::create([
            'asset_id' => $asset->id,
            'code' => 'EMP-'.self::$seq,
            'name' => 'Employee '.self::$seq,
            'hire_date' => '2024-01-01',
            'base_salary' => 4000,
            'payment_method' => 'bank',
            'status' => 'active',
        ]);
    }

    /** Approve, answering with the resulting status or `REFUSED`. */
    public static function approve(Payroll $run): string
    {
        try {
            $run->update(['status' => 'approved']);

            return $run->fresh()->status;
        } catch (DomainException) {
            return 'REFUSED';
        }
    }
}
