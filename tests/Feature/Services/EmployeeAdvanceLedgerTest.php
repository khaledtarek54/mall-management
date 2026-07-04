<?php

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\RecordAdvanceRepaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
});

function advEmployee(array $attrs = []): Employee
{
    return Employee::create(array_merge([
        'asset_id' => makeAsset()->id,
        'code' => 'E-'.uniqid(),
        'name' => 'Sara Ali',
        'hire_date' => now()->startOfYear()->toDateString(),
        'base_salary' => 6000,
        'payment_method' => 'bank',
    ], $attrs));
}

function grantAdvance(Employee $employee, array $attrs = []): EmployeeAdvance
{
    return app(GrantEmployeeAdvanceService::class)->grant($employee, array_merge([
        'amount' => 3000,
        'advance_date' => now()->toDateString(),
        'paid_from' => 'cash',
    ], $attrs));
}

/** Assert every listed chart account nets to ~0. */
function advExpectAllZero(array $codes): void
{
    foreach ($codes as $code) {
        $account = LedgerAccount::where('code', $code)->first();
        $statement = app(LedgerReportService::class)->accountLedger($account);
        expect($statement['closing'])->toEqualWithDelta(0.0, 0.001, "account {$code} should net to zero");
    }
}

/* ---- Grant --------------------------------------------------------------- */

it('journalizes an advance grant as Dr Employee Advances / Cr Cash', function () {
    $emp = advEmployee();
    $advance = grantAdvance($emp, ['amount' => 3000, 'paid_from' => 'cash']);

    $entry = $this->poster->post($advance);

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    expect((int) $entry->asset_id)->toBe($emp->asset_id);

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('employee_advances')]->debit)->toEqualWithDelta(3000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('cash')]->credit)->toEqualWithDelta(3000.0, 0.001);
    // Employee Advances is its own receivable — never touches AR/AP (tie-out-safe).
    expect($byAccount->has($this->accounts->id('accounts_receivable')))->toBeFalse();
    expect($byAccount->has($this->accounts->id('accounts_payable')))->toBeFalse();
});

it('credits the bank when the advance is paid from bank', function () {
    $advance = grantAdvance(advEmployee(), ['paid_from' => 'bank']);

    $byAccount = $this->poster->post($advance)->lines->keyBy('ledger_account_id');
    expect($byAccount->has($this->accounts->id('bank')))->toBeTrue();
    expect($byAccount->has($this->accounts->id('cash')))->toBeFalse();
});

it('rejects granting an advance to a terminated employee', function () {
    $emp = advEmployee();
    $emp->update(['status' => 'terminated']);

    expect(fn () => grantAdvance($emp->fresh()))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects a zero or negative advance amount', function () {
    $emp = advEmployee();

    expect(fn () => grantAdvance($emp, ['amount' => 0]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => grantAdvance($emp, ['amount' => -500]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(App\Models\EmployeeAdvance::count())->toBe(0);
});

/* ---- Repayment ----------------------------------------------------------- */

it('journalizes a repayment as Dr Cash / Cr Employee Advances', function () {
    $advance = grantAdvance(advEmployee(), ['amount' => 3000]);
    $repayment = app(RecordAdvanceRepaymentService::class)->record($advance, [
        'amount' => 1000, 'repaid_on' => now()->toDateString(), 'method' => 'cash',
    ]);

    $entry = $this->poster->post($repayment);
    expect($entry->isBalanced())->toBeTrue();

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('cash')]->debit)->toEqualWithDelta(1000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('employee_advances')]->credit)->toEqualWithDelta(1000.0, 0.001);
});

it('derives repaid + outstanding from the repayments', function () {
    $advance = grantAdvance(advEmployee(), ['amount' => 3000]);
    $svc = app(RecordAdvanceRepaymentService::class);
    $svc->record($advance, ['amount' => 1000, 'repaid_on' => now()->toDateString()]);
    $svc->record($advance, ['amount' => 500, 'repaid_on' => now()->toDateString()]);

    expect($advance->fresh()->repaid())->toBe(1500.0);
    expect($advance->fresh()->outstanding())->toBe(1500.0);
});

it('rejects a repayment that exceeds the outstanding balance', function () {
    $advance = grantAdvance(advEmployee(), ['amount' => 3000]);
    app(RecordAdvanceRepaymentService::class)->record($advance, ['amount' => 3000, 'repaid_on' => now()->toDateString()]);

    expect(fn () => app(RecordAdvanceRepaymentService::class)->record($advance->fresh(), ['amount' => 1, 'repaid_on' => now()->toDateString()]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

/* ---- Lifecycle ----------------------------------------------------------- */

it('nets Employee Advances to zero after a full repayment', function () {
    $advance = grantAdvance(advEmployee(), ['amount' => 3000, 'paid_from' => 'cash']);
    $this->poster->sync($advance->fresh());
    $repayment = app(RecordAdvanceRepaymentService::class)->record($advance, ['amount' => 3000, 'repaid_on' => now()->toDateString(), 'method' => 'cash']);
    $this->poster->sync($repayment->fresh());

    // Grant Dr 3000 offset by repayment Cr 3000 → the receivable clears; cash nets to 0.
    advExpectAllZero(['11203001', '11101001']);
});

it('voids the grant AND repayment entries through the WINDOWED sweep on soft-delete', function () {
    $advance = grantAdvance(advEmployee(), ['amount' => 3000]);
    $repayment = app(RecordAdvanceRepaymentService::class)->record($advance, ['amount' => 1000, 'repaid_on' => now()->toDateString()]);

    $this->poster->sync($advance->fresh());
    $this->poster->sync($repayment->fresh());
    // Age the repayment outside the sweep window — the delete cascade must re-touch it.
    DB::table('employee_advance_repayments')->where('id', $repayment->id)->update(['updated_at' => now()->subDays(30)]);

    $advance->delete();
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    advExpectAllZero(['11203001', '11101001']);
    expect(EmployeeAdvanceRepayment::withTrashed()->find($repayment->id)->trashed())->toBeTrue();
});
