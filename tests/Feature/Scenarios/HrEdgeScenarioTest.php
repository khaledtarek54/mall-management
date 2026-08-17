<?php

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\LedgerAccount;
use App\Models\Payroll;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\RecordAdvanceRepaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * HR EDGE cases (module 24) — the negative / boundary / state-transition / scoping /
 * RBAC classes the happy-path HrScenarioTest doesn't cover. Service + model level.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
    $this->report = app(LedgerReportService::class);
});

function hrEdgeEmployee(?int $assetId = null): Employee
{
    return Employee::create([
        'asset_id' => $assetId ?? makeAsset()->id,
        'code' => 'E-'.uniqid(),
        'name' => 'Omar Fahmy',
        'hire_date' => now()->startOfYear()->toDateString(),
        'base_salary' => 8000,
        'payment_method' => 'bank',
    ]);
}

function hrEdgeClosing(string $code): float
{
    $a = LedgerAccount::where('code', $code)->first();

    return round((float) app(LedgerReportService::class)->accountLedger($a)['closing'], 2);
}

// ---- NEGATIVE: zero / negative amounts are rejected ---------------------------------

it('rejects a zero or negative advance grant', function () {
    $emp = hrEdgeEmployee();
    $svc = app(GrantEmployeeAdvanceService::class);

    expect(fn () => $svc->grant($emp, ['amount' => 0, 'advance_date' => now()->toDateString()]))
        ->toThrow(HttpException::class);
    expect(fn () => $svc->grant($emp, ['amount' => -500, 'advance_date' => now()->toDateString()]))
        ->toThrow(HttpException::class);

    expect($emp->advances()->count())->toBe(0);
});

it('rejects a grant to a terminated employee', function () {
    $emp = hrEdgeEmployee();
    $emp->update(['status' => 'terminated', 'terminated_on' => now()->toDateString()]);

    expect(fn () => app(GrantEmployeeAdvanceService::class)
        ->grant($emp->fresh(), ['amount' => 1000, 'advance_date' => now()->toDateString()]))
        ->toThrow(HttpException::class);

    expect($emp->advances()->count())->toBe(0);
});

// ---- BOUNDARY: over-repayment guard, exact-outstanding boundary ----------------------

it('guards against over-repayment and honors the exact-outstanding boundary', function () {
    $emp = hrEdgeEmployee();
    $advance = app(GrantEmployeeAdvanceService::class)
        ->grant($emp, ['amount' => 2000, 'advance_date' => now()->toDateString(), 'paid_from' => 'cash']);
    $svc = app(RecordAdvanceRepaymentService::class);

    // Cannot repay MORE than outstanding.
    expect(fn () => $svc->record($advance, ['amount' => 2500, 'repaid_on' => now()->toDateString()]))
        ->toThrow(HttpException::class);
    // Cannot repay zero.
    expect(fn () => $svc->record($advance, ['amount' => 0, 'repaid_on' => now()->toDateString()]))
        ->toThrow(HttpException::class);

    // Repay the EXACT outstanding — allowed, drives outstanding to 0.
    $svc->record($advance, ['amount' => 2000, 'repaid_on' => now()->toDateString()]);
    expect($advance->fresh()->outstanding())->toBe(0.0);

    // Now that nothing is outstanding, any further repayment is rejected.
    expect(fn () => $svc->record($advance->fresh(), ['amount' => 1, 'repaid_on' => now()->toDateString()]))
        ->toThrow(HttpException::class);
    expect($advance->fresh()->repaid())->toBe(2000.0);
});

// ---- STATE-TRANSITION: draft not postable; approved posts; idempotent; void on cancel

it('posts a payroll run only once approved and voids it when cancelled', function () {
    $asset = makeAsset();
    $run = Payroll::create([
        'asset_id' => $asset->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 10000, 'salary_tax' => 1000, 'social_insurance' => 500,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);

    // DRAFT is not postable — no GL, no salaries expense recognised.
    expect($run->isPostable())->toBeFalse();
    expect($this->poster->sync($run->fresh()))->toBeNull();
    expect(hrEdgeClosing('51101001'))->toBe(0.0);

    // APPROVED → postable → salaries expense recognised.
    $run->update(['status' => 'approved']);
    $entry = $this->poster->sync($run->fresh());
    expect($entry)->not->toBeNull();
    expect(hrEdgeClosing('51101001'))->toBe(10000.0);

    // Re-syncing an unchanged approved run is idempotent — same entry, no double post.
    $again = $this->poster->sync($run->fresh());
    expect($again->id)->toBe($entry->id);
    expect(hrEdgeClosing('51101001'))->toBe(10000.0);

    // CANCELLED → no longer postable → the entry is voided, expense unwinds.
    $run->update(['status' => 'cancelled']);
    expect($run->fresh()->isPostable())->toBeFalse();
    $this->poster->sync($run->fresh());
    expect(hrEdgeClosing('51101001'))->toBe(0.0);

    expect($this->report->trialBalance()['balanced'])->toBeTrue();
});

// ---- SCOPING: advance denormalises the property from its employee ---------------------

it('scopes an advance to its employee property via denormalised asset_id', function () {
    $assetA = makeAsset();
    $assetB = makeAsset();
    $empA = hrEdgeEmployee($assetA->id);
    $empB = hrEdgeEmployee($assetB->id);

    $advA = app(GrantEmployeeAdvanceService::class)
        ->grant($empA, ['amount' => 1000, 'advance_date' => now()->toDateString()]);
    $advB = app(GrantEmployeeAdvanceService::class)
        ->grant($empB, ['amount' => 2000, 'advance_date' => now()->toDateString()]);

    expect($advA->asset_id)->toBe($assetA->id);
    expect($advB->asset_id)->toBe($assetB->id);

    // A property-scoped query returns only that property's advances.
    $forA = EmployeeAdvance::where('asset_id', $assetA->id)->pluck('id');
    expect($forA)->toContain($advA->id)->not->toContain($advB->id);

    // A repayment inherits the advance's property dimension.
    $rep = app(RecordAdvanceRepaymentService::class)
        ->record($advB, ['amount' => 500, 'repaid_on' => now()->toDateString()]);
    expect($rep->asset_id)->toBe($assetB->id);
});

// ---- RBAC: hr / accounting can; marketing / leasing cannot ---------------------------

it('grants HR-money permissions to hr and accounting but not marketing or leasing', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $hr = makeUser('hr');
    $accounting = makeUser('accounting');
    $marketing = makeUser('marketing');
    $leasing = makeUser('leasing');

    // HR manages the employee register + advances/repayments.
    expect($hr->can('employees.grant_advance'))->toBeTrue();
    expect($hr->can('employees.record_repayment'))->toBeTrue();
    expect($hr->can('employees.create'))->toBeTrue();

    // Accounting approves payroll runs and touches the same money actions.
    expect($accounting->can('payrolls.approve'))->toBeTrue();
    expect($accounting->can('employees.grant_advance'))->toBeTrue();
    expect($accounting->can('employees.record_repayment'))->toBeTrue();

    // Marketing / leasing have no HR-money reach at all.
    foreach (['employees.grant_advance', 'employees.record_repayment', 'payrolls.approve', 'payrolls.create'] as $perm) {
        expect($marketing->can($perm))->toBeFalse();
        expect($leasing->can($perm))->toBeFalse();
    }

    // HR does NOT approve payroll (that's an accounting action).
    expect($hr->can('payrolls.approve'))->toBeFalse();
});
