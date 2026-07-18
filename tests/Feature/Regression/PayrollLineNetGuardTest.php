<?php

use App\Models\Employee;
use App\Models\Payroll;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Regression — gap-analysis **F-90b** (module 24): a payroll LINE's net could go negative.
 *
 * THE BUG. `PayrollService::approve()` refuses a net-negative run, but that guard is on the
 * HEADER — an aggregate. One employee's deductions can exceed their gross (insurance 6,000
 * keyed for a 5,000 gross) while the run sums positive, so approve() passes, the GL posts, and
 * that employee's payslip PDF prints "Net −1,000" on a run that is now frozen and can't be
 * corrected line-by-line.
 *
 * THE FIX. `PayrollLine::booted()` refuses to save a line whose net (gross − tax − insurance)
 * is below zero — the invariant backstop. The relation-manager form validates the same thing
 * inline so the operator sees it on the field rather than a 500.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->asset = makeAsset(['code' => 'PLN']);
});

function netGuardRun(int $assetId): Payroll
{
    return Payroll::create([
        'asset_id' => $assetId,
        'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);
}

function netGuardEmployee(int $assetId): Employee
{
    return Employee::create([
        'asset_id' => $assetId, 'code' => 'PLN-'.uniqid(), 'name' => 'Ahmed',
        'hire_date' => '2026-01-01', 'base_salary' => 5000, 'payment_method' => 'bank',
    ]);
}

it('refuses a line whose deductions exceed its gross (net < 0)', function () {
    $run = netGuardRun($this->asset->id);

    // 5,000 gross, 6,000 insurance (600 intended) → net −1,000.
    expect(fn () => $run->lines()->create([
        'employee_id' => netGuardEmployee($this->asset->id)->id,
        'gross' => 5000, 'salary_tax' => 0, 'social_insurance' => 6000,
    ]))->toThrow(DomainException::class);

    // Nothing was written, and the header stays clean.
    expect($run->fresh()->lines()->count())->toBe(0)
        ->and((float) $run->fresh()->social_insurance)->toBe(0.0);
});

it('refuses an EDIT that drives an existing line net negative', function () {
    $run = netGuardRun($this->asset->id);
    $line = $run->lines()->create([
        'employee_id' => netGuardEmployee($this->asset->id)->id,
        'gross' => 5000, 'salary_tax' => 0, 'social_insurance' => 500,
    ]);

    expect(fn () => $line->update(['social_insurance' => 6000]))->toThrow(DomainException::class);

    // The good value is untouched.
    expect((float) $line->fresh()->social_insurance)->toBe(500.0);
});

it('allows a line whose net is exactly zero and a normal positive line', function () {
    $run = netGuardRun($this->asset->id);

    $zero = $run->lines()->create([
        'employee_id' => netGuardEmployee($this->asset->id)->id,
        'gross' => 5000, 'salary_tax' => 3000, 'social_insurance' => 2000, // net 0
    ]);
    $positive = $run->lines()->create([
        'employee_id' => netGuardEmployee($this->asset->id)->id,
        'gross' => 8000, 'salary_tax' => 800, 'social_insurance' => 600, // net 6,600
    ]);

    expect($zero->net)->toBe(0.0)
        ->and($positive->net)->toBe(6600.0);
});
