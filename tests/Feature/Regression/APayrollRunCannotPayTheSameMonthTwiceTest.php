<?php

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Payroll;
use App\Services\PayrollService;
use App\Support\ConcurrencyPolicy;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Tests\Support\LockSpy;

/**
 * TWO PAYROLL RUNS FOR ONE MONTH, APPROVED AT ONCE, PAID EVERYBODY TWICE.
 *
 * `Payroll::saving` carries the double-pay guard — no employee may be on two APPROVED runs for one
 * month at one property — and it was a plain read with nothing serialising the writers. Two runs
 * approved concurrently each see the other still `draft`, both pass, and the employee is paid twice:
 * salaries posted twice, and every advance installment in both runs relieved twice.
 *
 * There is no contended ROW to lock — the two runs are different rows and the guard is about the
 * SET — so it is a cache lock, keyed on the property and the month because that is exactly the
 * scope of the guard's own query. Taken OUTSIDE the transaction, or our consistent-read snapshot
 * would already be fixed from before the other approval committed and the guard would be answered
 * from a state it had waited past.
 *
 * **And the advance re-check decided from a pre-lock snapshot.** It takes `lockForUpdate()` on each
 * `EmployeeAdvance` and then asked `outstanding()`, which issues plain reads against the repayments
 * and the approved payroll lines. A lock serialises writers; it does not make the guard behind it
 * SEE them — so two runs each deducting within the pre-approval outstanding both passed and together
 * over-repaid the loan. `outstandingForUpdate()` is the locking twin.
 *
 * **What none of this proves is that two transactions actually serialise** — that needs MySQL and
 * two connections (`docs/qa/scripts/race.sh`). What it proves is that the locks are taken and the
 * guards read under them, which is what stops the next tidy-up deleting either.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'PAY']);
    $this->month = CarbonImmutable::now()->startOfMonth();

    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-100', 'name' => 'Mona Adel',
        'position' => 'Technician', 'hire_date' => '2024-01-01',
        'base_salary' => 8000, 'payment_method' => 'bank',
    ]);

    $this->svc = app(PayrollService::class);
});

/** A draft run for the fixture month, carrying one line for the fixture employee. */
function draftRun(float $net = 8000, ?EmployeeAdvance $advance = null, float $deduction = 0): Payroll
{
    $run = Payroll::create([
        'asset_id' => test()->asset->id,
        'period_month' => test()->month->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);

    $run->lines()->create([
        'employee_id' => test()->employee->id,
        'employee_advance_id' => $advance?->id,
        'gross' => $net + $deduction,
        'allowances' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
        'advance_deduction' => $deduction,
        'other_deductions' => 0, 'employer_social_insurance' => 0,
    ]);

    return $run->fresh();
}

it('refuses to approve a second run for the same employee and month', function () {
    $first = draftRun();
    $second = draftRun();

    expect($this->svc->approve($first)->status)->toBe('approved');

    // The control and the refusal together: a guard that refused everything would satisfy the
    // second assertion while stopping payroll entirely.
    expect(fn () => $this->svc->approve($second->fresh()))->toThrow(DomainException::class);

    expect($second->fresh()->status)->toBe('draft');
});

it('serialises the approval on a lock keyed to the property and month', function () {
    // The cache lock is what makes the guard's re-read authoritative. Asserted through the registry
    // and the source, because a cache lock leaves no SQL for `LockSpy` to see — and the registry is
    // what `ConcurrencyPolicyConformanceTest` holds to a count, so a deleted lock turns the build
    // red rather than turning nothing red.
    $source = file_get_contents(base_path('app/Services/PayrollService.php'));

    expect($source)->toContain("Cache::lock(")
        ->and($source)->toContain('payroll:approve:')
        // Keyed on both, or two malls that cannot clash would serialise against each other.
        ->and($source)->toContain('$payroll->asset_id')
        ->and(ConcurrencyPolicy::expected())
        ->toHaveKey('app/Services/PayrollService.php')
        // …and the guard that reads the advance balance is registered as AUTHORITATIVE, which is
        // what makes the gate read its method body and require a locking read in it.
        ->and(ConcurrencyPolicy::AUTHORITATIVE_GUARDS)
        ->toHaveKey('App\\Models\\EmployeeAdvance::outstandingForUpdate');
});

it('reads the advance balance UNDER the lock it just took', function () {
    // `AUTHORITATIVE_GUARDS` claims this, and the gate reads the method's own body — but the claim
    // is only worth anything if the guard is actually reached, so this drives the real approval and
    // watches which tables were locked.
    $advance = EmployeeAdvance::create([
        'asset_id' => $this->asset->id, 'employee_id' => $this->employee->id,
        'type' => 'loan', 'amount' => 3000,
        'advance_date' => $this->month->toDateString(), 'paid_from' => 'cash',
    ]);

    $run = draftRun(7000, $advance, 1000);

    $spy = LockSpy::watch(fn () => $this->svc->approve($run));

    expect($spy->locked('employee_advances'))->toBeTrue(
        'the advance row was not locked. Locked: '.implode(', ', $spy->lockedTables()))
        ->and($spy->locked('payroll_lines'))->toBeTrue(
            'the outstanding balance was read WITHOUT a lock, so it answers from the snapshot taken '
            .'before this transaction waited — two runs then over-repay the same advance');
});

it('refuses a run that would over-repay an advance', function () {
    // The guard the locking read exists to make authoritative, on its own terms.
    $advance = EmployeeAdvance::create([
        'asset_id' => $this->asset->id, 'employee_id' => $this->employee->id,
        'type' => 'loan', 'amount' => 1000,
        'advance_date' => $this->month->toDateString(), 'paid_from' => 'cash',
    ]);

    $run = draftRun(6000, $advance, 2000);   // 2,000 against a 1,000 loan

    expect(fn () => $this->svc->approve($run))->toThrow(DomainException::class);

    expect($run->fresh()->status)->toBe('draft');
});

it('still approves an ordinary run — the control for every refusal above', function () {
    $run = draftRun();

    expect($this->svc->approve($run)->status)->toBe('approved')
        ->and($run->fresh()->approved_at)->not->toBeNull();
});
