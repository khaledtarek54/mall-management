<?php

use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Models\Employee;
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

it('exports the per-employee register with net = gross − tax − insurance', function () {
    $asset = makeAsset();
    $run = registerRun($asset->id);
    $run->lines()->create(['employee_id' => registerEmployee($asset->id, 'E-1', 'Mona Adel')->id,
        'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500]);

    $csv = PayrollResource::registerCsv($run);
    $row = collect($csv['rows'])->firstWhere(0, 'E-1');

    // gross 10000, tax 1000, insurance 500, net 8500.
    expect($row[1])->toBe('Mona Adel')
        ->and((float) $row[3])->toBe(10000.0)
        ->and((float) $row[4])->toBe(1000.0)
        ->and((float) $row[5])->toBe(500.0)
        ->and((float) $row[6])->toBe(8500.0);
});

it('closes the register with totals that tie to the run header', function () {
    $asset = makeAsset();
    $run = registerRun($asset->id);
    $run->lines()->create(['employee_id' => registerEmployee($asset->id, 'E-1', 'Mona')->id,
        'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500]);
    $run->lines()->create(['employee_id' => registerEmployee($asset->id, 'E-2', 'Sara')->id,
        'gross' => 6000, 'salary_tax' => 400, 'social_insurance' => 300]);

    $csv = PayrollResource::registerCsv($run);
    $total = collect($csv['rows'])->last();

    // Totals: gross 16000, tax 1400, insurance 800, net 13800 — and they tie to the derived header.
    expect((float) $total[3])->toBe(16000.0)
        ->and((float) $total[4])->toBe(1400.0)
        ->and((float) $total[5])->toBe(800.0)
        ->and((float) $total[6])->toBe(13800.0)
        ->and(round((float) $run->fresh()->net_paid, 2))->toBe(13800.0);
});
