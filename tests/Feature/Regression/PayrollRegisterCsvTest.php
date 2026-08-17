<?php

use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Payroll;

/**
 * Module 24 UX pass — a payroll run's per-employee register (muster roll) is exportable to CSV: every
 * employee's gross, statutory withholdings and net pay, plus totals that tie to the run header. The
 * per-employee payslips are the same figures one PDF at a time; the register is the consolidated view
 * HR/finance works each month.
 */
function registerEmployee(int $assetId, string $code, string $name): Employee
{
    return Employee::create([
        'asset_id' => $assetId, 'code' => $code, 'name' => $name, 'position' => 'Engineer',
        'hire_date' => '2026-01-01', 'base_salary' => 8000, 'payment_method' => 'bank',
    ]);
}

function registerRun(int $assetId): Payroll
{
    return Payroll::create([
        'asset_id' => $assetId,
        'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);
}

// Columns: [0]code [1]name [2]position [3]basic [4]allowances [5]gross [6]tax [7]insurance [8]advance [9]other [10]net [11]employer_si
it('exports the per-employee register with the earnings breakdown + net', function () {
    $asset = makeAsset();
    $run = registerRun($asset->id);
    $emp = registerEmployee($asset->id, 'E-1', 'Mona Adel');
    $advance = EmployeeAdvance::create(['employee_id' => $emp->id, 'asset_id' => $asset->id,
        'type' => 'loan', 'amount' => 5000, 'advance_date' => '2026-01-01', 'paid_from' => 'cash']);
    $run->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'allowances' => 2000, 'salary_tax' => 1000, 'social_insurance' => 500,
        'advance_deduction' => 700, 'other_deductions' => 300, 'employer_social_insurance' => 1875]);

    $csv = PayrollResource::registerCsv($run);
    $row = collect($csv['rows'])->firstWhere(0, 'E-1');

    // basic 8000, allowances 2000, gross 10000, tax 1000, insurance 500, advance 700, other 300,
    // net 7500 (= 10000 − 1000 − 500 − 700 − 300), employer SI 1875 (does not affect net).
    expect($row[1])->toBe('Mona Adel')
        ->and((float) $row[3])->toBe(8000.0)
        ->and((float) $row[4])->toBe(2000.0)
        ->and((float) $row[5])->toBe(10000.0)
        ->and((float) $row[6])->toBe(1000.0)
        ->and((float) $row[7])->toBe(500.0)
        ->and((float) $row[8])->toBe(700.0)
        ->and((float) $row[9])->toBe(300.0)
        ->and((float) $row[10])->toBe(7500.0)
        ->and((float) $row[11])->toBe(1875.0);
});

it('closes the register with totals that tie to the run header', function () {
    $asset = makeAsset();
    $run = registerRun($asset->id);
    $run->lines()->create(['employee_id' => registerEmployee($asset->id, 'E-1', 'Mona')->id,
        'gross' => 10000, 'allowances' => 2000, 'salary_tax' => 1000, 'social_insurance' => 500, 'employer_social_insurance' => 1875]);
    $run->lines()->create(['employee_id' => registerEmployee($asset->id, 'E-2', 'Sara')->id,
        'gross' => 6000, 'allowances' => 1000, 'salary_tax' => 400, 'social_insurance' => 300, 'employer_social_insurance' => 1125]);

    $csv = PayrollResource::registerCsv($run);
    $total = collect($csv['rows'])->last();

    // Totals: basic 13000, allowances 3000, gross 16000, tax 1400, insurance 800, advance 0,
    // other 0, net 13800, employer SI 3000 — and the net ties to the derived header.
    expect((float) $total[3])->toBe(13000.0)
        ->and((float) $total[4])->toBe(3000.0)
        ->and((float) $total[5])->toBe(16000.0)
        ->and((float) $total[6])->toBe(1400.0)
        ->and((float) $total[7])->toBe(800.0)
        ->and((float) $total[8])->toBe(0.0)
        ->and((float) $total[9])->toBe(0.0)
        ->and((float) $total[10])->toBe(13800.0)
        ->and((float) $total[11])->toBe(3000.0)
        ->and(round((float) $run->fresh()->net_paid, 2))->toBe(13800.0)
        ->and(round((float) $run->fresh()->employer_social_insurance, 2))->toBe(3000.0);
});
