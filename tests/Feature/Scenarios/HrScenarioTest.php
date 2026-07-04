<?php

use App\Models\Employee;
use App\Models\LedgerAccount;
use App\Models\Payroll;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\PayslipPdfService;
use App\Services\RecordAdvanceRepaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * End-to-end HR: hire → advance (سلف) grant + repayment on the GL, then a payroll run
 * broken down per employee whose header derives from the lines and posts the aggregate.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
    $this->report = app(LedgerReportService::class);
});

function hrEmployee(?int $assetId = null): Employee
{
    return Employee::create([
        'asset_id' => $assetId ?? makeAsset()->id, 'code' => 'E-'.uniqid(), 'name' => 'Layla Samir',
        'hire_date' => now()->startOfYear()->toDateString(), 'base_salary' => 9000, 'payment_method' => 'bank',
    ]);
}

function hrClosing(string $code): float
{
    $a = LedgerAccount::where('code', $code)->first();

    return round((float) app(LedgerReportService::class)->accountLedger($a)['closing'], 2);
}

it('grants and repays an advance with a balanced GL and derived outstanding', function () {
    $emp = hrEmployee();
    $advance = app(GrantEmployeeAdvanceService::class)->grant($emp, ['amount' => 3000, 'advance_date' => now()->toDateString(), 'paid_from' => 'cash']);
    $this->poster->sync($advance->fresh());

    // Employee owes 3000 (receivable, debit-normal → +3000); cash out 3000.
    expect(hrClosing('11203001'))->toBe(3000.0);
    expect($advance->fresh()->outstanding())->toBe(3000.0);

    // Repay 1000 to bank.
    $rep = app(RecordAdvanceRepaymentService::class)->record($advance, ['amount' => 1000, 'repaid_on' => now()->toDateString(), 'method' => 'bank']);
    $this->poster->sync($rep->fresh());

    expect(hrClosing('11203001'))->toBe(2000.0); // receivable reduced
    expect($advance->fresh()->outstanding())->toBe(2000.0);

    $tb = $this->report->trialBalance();
    expect($tb['balanced'])->toBeTrue();
});

it('runs a per-employee payroll whose header derives from Σ lines and posts the aggregate', function () {
    $asset = makeAsset();
    $run = Payroll::create([
        'asset_id' => $asset->id, 'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'paid_from' => 'bank', 'status' => 'draft',
    ]);
    $run->lines()->create(['employee_id' => hrEmployee($asset->id)->id, 'gross' => 12000, 'salary_tax' => 1500, 'social_insurance' => 800]);
    $run->lines()->create(['employee_id' => hrEmployee($asset->id)->id, 'gross' => 8000, 'salary_tax' => 700, 'social_insurance' => 500]);

    // Header derives from Σ lines.
    $run->refresh();
    expect((float) $run->gross_salaries)->toBe(20000.0);
    expect((float) $run->net_paid)->toBe(16500.0); // 20000 − 2200 tax − 1300 insurance

    $run->update(['status' => 'approved']);
    $this->poster->sync($run->fresh());

    expect((float) hrClosing('51101001'))->toBe(20000.0); // Salaries expense = Σ gross
    expect(hrClosing('21302001'))->toBe(2200.0);          // Salary tax payable
    expect(hrClosing('21601001'))->toBe(1300.0);          // Social insurance payable
    expect(hrClosing('11102001'))->toBe(-16500.0);        // Bank paid out (asset debit-normal, credited)

    // A payslip renders for a line.
    $line = $run->lines()->first();
    expect(substr(app(PayslipPdfService::class)->build($line->fresh()), 0, 4))->toBe('%PDF');

    $tb = $this->report->trialBalance();
    expect($tb['balanced'])->toBeTrue();
});
