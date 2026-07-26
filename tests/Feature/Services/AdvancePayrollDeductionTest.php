<?php

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\PayrollService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Advance repayment via payroll deduction (module 24, Phase 4b — closes the سلف loop).
 * The installment reduces net pay and, on approval, the payroll GL entry credits Employee
 * Advances; the advance's outstanding derives to include APPROVED-run deductions (so a
 * cancelled run's deduction stops counting). Exhaustive scenarios: GL tie-out through the
 * real approve + sweep, outstanding tracking, over-repay + net guards, cancel-restores.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->asset = makeAsset();
    $this->accounts = app(AccountResolver::class);
});

function apdEmployee(int $assetId): Employee
{
    return Employee::create(['asset_id' => $assetId, 'code' => 'E-'.uniqid(), 'name' => 'Loanee',
        'hire_date' => '2026-01-01', 'base_salary' => 10000, 'payment_method' => 'bank']);
}

function apdAdvance(Employee $emp, float $amount = 5000): EmployeeAdvance
{
    return EmployeeAdvance::create(['employee_id' => $emp->id, 'asset_id' => $emp->asset_id,
        'type' => 'loan', 'amount' => $amount, 'advance_date' => '2026-01-01', 'paid_from' => 'cash']);
}

function apdRun(int $assetId): Payroll
{
    return Payroll::create(['asset_id' => $assetId, 'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'net_paid' => 0,
        'paid_from' => 'bank', 'status' => 'draft']);
}

/** The single posted (non-void) entry for a payroll run. */
function apdEntry(Payroll $run): ?JournalEntry
{
    return app(\App\Services\Accounting\LedgerPoster::class)->sync($run->fresh());
}

it('deducts an installment: reduces net + credits Employee Advances, and the entry balances', function () {
    $emp = apdEmployee($this->asset->id);
    $advance = apdAdvance($emp, 5000);
    $run = apdRun($this->asset->id);
    $run->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500, 'advance_deduction' => 800]);

    // Header derives: net = 10000 − 1000 − 500 − 800 = 7700; advance_deductions = 800.
    $run->refresh();
    expect((float) $run->net_paid)->toBe(7700.0);
    expect((float) $run->advance_deductions)->toBe(800.0);

    app(PayrollService::class)->approve($run);
    $entry = apdEntry($run);

    expect($entry->isBalanced())->toBeTrue();
    $by = $entry->lines->keyBy('ledger_account_id');
    expect((float) $by[$this->accounts->id('salaries_expense')]->debit)->toEqualWithDelta(10000.0, 0.001);
    expect((float) $by[$this->accounts->id('employee_advances')]->credit)->toEqualWithDelta(800.0, 0.001); // loan reduced
    expect((float) $by[$this->accounts->id('bank')]->credit)->toEqualWithDelta(7700.0, 0.001);             // net reduced
});

it('reduces the advance outstanding only once the run is APPROVED', function () {
    $emp = apdEmployee($this->asset->id);
    $advance = apdAdvance($emp, 5000);
    $run = apdRun($this->asset->id);
    $run->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'salary_tax' => 0, 'social_insurance' => 0, 'advance_deduction' => 2000]);

    // Draft: not yet counted.
    expect($advance->fresh()->outstanding())->toBe(5000.0);

    app(PayrollService::class)->approve($run);

    // Approved: outstanding drops by the installment; repaid ties to amount.
    expect($advance->fresh()->outstanding())->toBe(3000.0);
    expect($advance->fresh()->repaidViaPayroll())->toBe(2000.0);
});

it('restores the advance outstanding when the run is cancelled', function () {
    $emp = apdEmployee($this->asset->id);
    $advance = apdAdvance($emp, 5000);
    $run = apdRun($this->asset->id);
    $run->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'salary_tax' => 0, 'social_insurance' => 0, 'advance_deduction' => 2000]);
    app(PayrollService::class)->approve($run);
    expect($advance->fresh()->outstanding())->toBe(3000.0);

    app(PayrollService::class)->cancel($run);

    // Cancelled run no longer counts — the loan balance is whole again.
    expect($advance->fresh()->outstanding())->toBe(5000.0);
});

it('refuses to approve when the run over-repays the advance (lock-safe re-check)', function () {
    $emp = apdEmployee($this->asset->id);
    $advance = apdAdvance($emp, 1000);         // only 1000 outstanding
    $run = apdRun($this->asset->id);
    $run->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'salary_tax' => 0, 'social_insurance' => 0, 'advance_deduction' => 1500]); // > outstanding

    expect(fn () => app(PayrollService::class)->approve($run))->toThrow(DomainException::class);
    expect($run->fresh()->status)->toBe('draft');       // not approved
    expect($advance->fresh()->outstanding())->toBe(1000.0);
});

it('two approved runs cannot together over-repay one advance', function () {
    $emp = apdEmployee($this->asset->id);
    $advance = apdAdvance($emp, 1000);
    $runA = apdRun($this->asset->id);
    $runA->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'salary_tax' => 0, 'social_insurance' => 0, 'advance_deduction' => 700]);
    $runB = apdRun($this->asset->id);
    $runB->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'salary_tax' => 0, 'social_insurance' => 0, 'advance_deduction' => 700]);

    app(PayrollService::class)->approve($runA);          // 700 ≤ 1000, ok → outstanding 300
    expect($advance->fresh()->outstanding())->toBe(300.0);

    expect(fn () => app(PayrollService::class)->approve($runB))->toThrow(DomainException::class); // 700 > 300
});

it('a manual cash repayment respects payroll installments already taken', function () {
    $emp = apdEmployee($this->asset->id);
    $advance = apdAdvance($emp, 5000);
    $run = apdRun($this->asset->id);
    $run->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'salary_tax' => 0, 'social_insurance' => 0, 'advance_deduction' => 4000]);
    app(PayrollService::class)->approve($run);           // outstanding now 1000
    expect($advance->fresh()->outstanding())->toBe(1000.0);

    // A cash repayment of 2000 must be refused (only 1000 left) — the service aborts 422.
    expect(fn () => app(\App\Services\RecordAdvanceRepaymentService::class)
        ->record($advance->fresh(), ['amount' => 2000, 'repaid_on' => now()->toDateString(), 'method' => 'cash']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects a line whose installment drives net negative', function () {
    $emp = apdEmployee($this->asset->id);
    $advance = apdAdvance($emp, 20000);
    $run = apdRun($this->asset->id);

    expect(fn () => $run->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 5000, 'salary_tax' => 1000, 'social_insurance' => 500, 'advance_deduction' => 4000])) // 5000−1000−500−4000 < 0
        ->toThrow(DomainException::class);
});

it('rejects an installment with no advance linked', function () {
    $emp = apdEmployee($this->asset->id);
    $run = apdRun($this->asset->id);

    expect(fn () => $run->lines()->create(['employee_id' => $emp->id,
        'gross' => 10000, 'advance_deduction' => 500])) // no employee_advance_id
        ->toThrow(DomainException::class);
});

it('clears the advance link when the installment is zeroed', function () {
    $emp = apdEmployee($this->asset->id);
    $advance = apdAdvance($emp, 5000);
    $run = apdRun($this->asset->id);
    $line = $run->lines()->create(['employee_id' => $emp->id, 'employee_advance_id' => $advance->id,
        'gross' => 10000, 'advance_deduction' => 500]);
    expect($line->fresh()->employee_advance_id)->toBe($advance->id);

    $line->update(['advance_deduction' => 0]);
    expect($line->fresh()->employee_advance_id)->toBeNull();
});
