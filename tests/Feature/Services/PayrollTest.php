<?php

use App\Models\Payroll;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\PayrollService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
    $this->svc = app(PayrollService::class);
});

function makePayroll(array $attrs = []): Payroll
{
    return Payroll::create(array_merge([
        'asset_id' => makeAsset()->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 50000,
        'salary_tax' => 6000,
        'social_insurance' => 4000,
        'paid_from' => 'bank',
        'status' => 'draft',
    ], $attrs));
}

it('derives net_paid = gross - tax - insurance on write', function () {
    expect((float) makePayroll()->fresh()->net_paid)->toEqualWithDelta(40000.0, 0.001);
});

it('coerces blank deduction strings without crashing', function () {
    $p = makePayroll(['salary_tax' => '', 'social_insurance' => '']);
    expect((float) $p->fresh()->salary_tax)->toEqualWithDelta(0.0, 0.001);
    expect((float) $p->fresh()->net_paid)->toEqualWithDelta(50000.0, 0.001);
});

it('approves a draft run (makes it postable)', function () {
    $p = makePayroll();
    expect($p->isPostable())->toBeFalse();
    $this->svc->approve($p);
    expect($p->fresh()->status)->toBe('approved');
    expect($p->fresh()->isPostable())->toBeTrue();
});

it('journalizes an approved run: Dr salaries / Cr tax + insurance + bank', function () {
    $p = makePayroll();
    $this->svc->approve($p);

    $entry = $this->poster->post($p->fresh());

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('salaries_expense')]->debit)->toEqualWithDelta(50000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('salary_tax_payable')]->credit)->toEqualWithDelta(6000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('social_insurance_payable')]->credit)->toEqualWithDelta(4000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('bank')]->credit)->toEqualWithDelta(40000.0, 0.001);
});

it('skips a draft run, and the journalizer defensively skips a net-negative run', function () {
    expect($this->poster->post(makePayroll()))->toBeNull(); // draft

    // approve() rejects net<0, so force an approved-but-malformed run to prove the
    // journalizer's own defensive skip (belt-and-suspenders for legacy/bypass data).
    $bad = makePayroll(['gross_salaries' => 1000, 'salary_tax' => 800, 'social_insurance' => 500, 'status' => 'approved']); // net -300
    expect($this->poster->post($bad->fresh()))->toBeNull();
});

it('approve() refuses a run whose deductions exceed gross', function () {
    $bad = makePayroll(['gross_salaries' => 1000, 'salary_tax' => 800, 'social_insurance' => 500]); // net -300, draft
    expect(fn () => $this->svc->approve($bad))->toThrow(DomainException::class);
    expect($bad->fresh()->status)->toBe('draft');
});

it('routes to cash when paid_from is cash and keeps the trial balance balanced', function () {
    $p = makePayroll(['paid_from' => 'cash', 'salary_tax' => 0, 'social_insurance' => 0]);
    $this->svc->approve($p);
    $entry = $this->poster->post($p->fresh());

    expect($entry->lines->keyBy('ledger_account_id')->has($this->accounts->id('cash')))->toBeTrue();
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('cancels a run (idempotent)', function () {
    $p = makePayroll();
    $this->svc->cancel($p);
    expect($p->fresh()->status)->toBe('cancelled');
    $this->svc->cancel($p->fresh());
    expect($p->fresh()->status)->toBe('cancelled');
});
