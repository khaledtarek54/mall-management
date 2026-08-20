<?php

/*
|--------------------------------------------------------------------------
| The payroll header's rollup was written twice (2026-08-20)
|--------------------------------------------------------------------------
| Seven identical sums plus the net existed in TWO places — `recomputeFromLines()`, called from the
| payslip save/delete hooks, and the `saving` hook that stops someone typing over the header while
| payslips exist. Both were needed, for different reasons, and both computed the same rule.
|
| They agreed, and that is the whole hazard: an EIGHTH component would have to be added to both, and
| the copy that was missed would produce a payroll header disagreeing with the payslips beneath it —
| the same divergence the invoice validation sweep closed on §8 R1. Several channels change the
| number, so exactly one method may compute it.
|
| Found by reading, not by a failure. Nothing was wrong; there were simply two of it.
*/

use App\Filament\Admin\RelationManagers\EmployeePayslipsRelationManager;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Payroll;
use App\Models\PayrollLine;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->employee = Employee::create([
        'asset_id' => $this->asset->id,
        'code' => 'EMP-TEST-01',
        'name' => 'Mahmoud Adel',
        'position' => 'Technician',
        'hire_date' => '2025-01-01',
        'base_salary' => 20000,
        'payment_method' => 'bank',
        'status' => 'active',
    ]);
});

function payrollRun($ctx, string $status = 'draft'): Payroll
{
    return Payroll::create([
        'asset_id' => $ctx->asset->id,
        'period_month' => '2026-08-01',
        'status' => $status,
        'paid_from' => 'bank',
    ]);
}

function payslipOn(Payroll $run, Employee $employee, array $overrides = []): PayrollLine
{
    return PayrollLine::create(array_merge([
        'payroll_id' => $run->id,
        'employee_id' => $employee->id,
        'gross' => 20000,
        'allowances' => 0,
        'salary_tax' => 2000,
        'social_insurance' => 1500,
        'advance_deduction' => 0,
        'other_deductions' => 0,
        'employer_social_insurance' => 3400,
    ], $overrides));
}

it('derives the header from the payslips', function () {
    $run = payrollRun($this);
    payslipOn($run, $this->employee);

    $run->refresh();

    // Employer social insurance is summed but NOT deducted — it is the employer's own cost.
    expect((float) $run->gross_salaries)->toBe(20000.0)
        ->and((float) $run->net_paid)->toBe(16500.0)
        ->and((float) $run->employer_social_insurance)->toBe(3400.0);
});

it('snaps a hand-typed header back to what the payslips say', function () {
    $run = payrollRun($this);
    payslipOn($run, $this->employee);

    $run->update(['gross_salaries' => 999999]);

    // The header an employee's payslip disagrees with is the one nobody can explain.
    expect((float) $run->fresh()->gross_salaries)->toBe(20000.0);
});

it('computes the header the SAME way on both paths', function () {
    $run = payrollRun($this);
    payslipOn($run, $this->employee);

    // Path A: the payslip hook. Path B: the header being edited. Two copies of these sums existed;
    // this asserts the one definition by driving both and comparing every component.
    $viaLineHook = $run->fresh()->only([
        'gross_salaries', 'allowances', 'salary_tax', 'social_insurance',
        'advance_deductions', 'other_deductions', 'employer_social_insurance', 'net_paid',
    ]);

    $run->update(['salary_tax' => 1]);

    expect($run->fresh()->only(array_keys($viaLineHook)))->toBe($viaLineHook);
});

it('zeroes the header when the last payslip is removed', function () {
    $run = payrollRun($this);
    $line = payslipOn($run, $this->employee);

    $line->delete();

    // Σ of no lines is 0 — never a stale line-derived total left on the books.
    expect((float) $run->fresh()->gross_salaries)->toBe(0.0)
        ->and((float) $run->fresh()->net_paid)->toBe(0.0);
});

it('repays an advance only once the run is APPROVED', function () {
    $advance = EmployeeAdvance::create([
        'asset_id' => $this->asset->id, 'employee_id' => $this->employee->id,
        'type' => 'advance', 'amount' => 12000,
        'advance_date' => '2026-06-01', 'paid_from' => 'bank',
    ]);

    $run = payrollRun($this);
    payslipOn($run, $this->employee, [
        'advance_deduction' => 2000,
        'employee_advance_id' => $advance->id,
    ]);

    // A DRAFT run has withheld nothing — reducing the loan before the money is withheld would
    // forgive 2,000 the employee still owes.
    expect($advance->fresh()->outstanding())->toBe(12000.0);

    $run->update(['status' => 'approved']);

    expect($advance->fresh()->outstanding())->toBe(10000.0);
});

it('freezes an approved run', function () {
    $run = payrollRun($this);
    payslipOn($run, $this->employee);
    $run->update(['status' => 'approved']);

    // Approval posts the run to the GL; restating it afterwards would desync the entry.
    expect(fn () => $run->update(['gross_salaries' => 1]))->toThrow(DomainException::class);
});

it('shows an employee what they have been paid, from their own record', function () {
    // `payrollLines` existed on the model and NO screen read it, so "what did this employee earn in
    // June, and what was deducted?" meant opening every payroll run in turn and finding their line
    // (2026-08-20). The relation manager is registered on the employee, not only on the run.
    $relations = EmployeeResource::getRelations();

    expect($relations)->toContain(EmployeePayslipsRelationManager::class)
        ->and($this->employee->payrollLines())->not->toBeNull();
});

it('mounts that tab', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Filament\Facades\Filament::setTenant($this->asset);

    $run = payrollRun($this);
    payslipOn($run, $this->employee);

    // A relation manager's columns and actions are wired when the table is BUILT, so a mistake
    // renders as a 500 on the tab and never as a failing unit test.
    Livewire\Livewire::test(EmployeePayslipsRelationManager::class, [
        'ownerRecord' => $this->employee,
        'pageClass' => EditEmployee::class,
    ])->assertOk();

    Filament\Facades\Filament::setTenant(null, isQuiet: true);
});
