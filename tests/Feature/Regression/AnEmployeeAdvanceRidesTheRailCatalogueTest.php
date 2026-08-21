<?php

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\RecordAdvanceRepaymentService;
use App\Support\ValueSets;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **The two money-rail columns EG-11 missed, and the two journalizers that went with them.**
 *
 * `CLAUDE.md` says the payment-rail catalogue serves five columns and six journalizers. It served
 * five and six; there were SEVEN and EIGHT. `employee_advances.paid_from` (money OUT to the staff
 * member) and `employee_advance_repayments.method` (money back IN) had **no `ValueSets` entry at
 * all**, and both journalizers carried the mirror ternary EG-11 removed from the other six —
 * `$x === 'bank' ? 'bank' : 'cash'` — so an advance paid by InstaPay credited the CASH account.
 *
 * `RecordAdvanceRepaymentService` made it quieter still: it clamped anything that was not `bank` to
 * `cash`, which is a WRONG RAIL rather than a refusal. The operator saw a success toast and the
 * money posted to the wrong account.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'AD']);
    $this->employee = Employee::create([
        'asset_id' => $this->asset->id,
        'code' => 'E-'.uniqid(),
        'name' => 'Amira Fouad',
        'hire_date' => now()->subYear()->toDateString(),
        'base_salary' => 9000,
        'payment_method' => 'bank',
    ]);

    // A postable asset account that is NEITHER the `cash` nor the `bank` role account — otherwise
    // "the rail's account" and "the account the old ternary hard-coded" are the same row and the
    // assertion below passes with the fix reverted. Proven by mutation: the first version of this
    // fixture grabbed the first postable asset account, which IS cash, and restoring the ternary
    // left the test green.
    $resolver = app(AccountResolver::class);
    $floors = [$resolver->id('cash', $this->asset->id), $resolver->id('bank', $this->asset->id)];

    $this->clearing = LedgerAccount::query()
        ->where('type', 'asset')
        ->where('is_postable', true)
        ->whereNotIn('id', $floors)
        ->firstOrFail();

    expect($this->clearing->id)->not->toBeIn($floors);

    $this->instapay = PaymentMethod::create([
        'code' => 'instapay',
        'name_en' => 'InstaPay',
        'name_ar' => 'انستا باي',
        'for_inbound' => true,
        'for_outbound' => true,
        'ledger_account_id' => $this->clearing->id,
    ]);
});

it('accepts an advance paid on a rail the operator activated, and credits that rail\'s account', function () {
    expect(ValueSets::allowed('employee_advances', 'paid_from'))->toContain('instapay');

    $advance = EmployeeAdvance::create([
        'employee_id' => $this->employee->id,
        'asset_id' => $this->asset->id,
        'type' => 'advance',
        'amount' => 4000,
        'advance_date' => now()->toDateString(),
        'paid_from' => 'instapay',
    ]);

    $entry = app(LedgerPoster::class)->post($advance->fresh());

    // The rail's own account, not the `cash` role the ternary hard-coded.
    expect($entry->lines->firstWhere('credit', '>', 0)->ledger_account_id)->toBe($this->clearing->id);
});

it('accepts a repayment on that rail too, and does not silently clamp it to cash', function () {
    expect(ValueSets::allowed('employee_advance_repayments', 'method'))->toContain('instapay');

    $advance = EmployeeAdvance::create([
        'employee_id' => $this->employee->id,
        'asset_id' => $this->asset->id,
        'type' => 'advance',
        'amount' => 4000,
        'advance_date' => now()->subMonth()->toDateString(),
        'paid_from' => 'cash',
    ]);

    $repayment = app(RecordAdvanceRepaymentService::class)->record($advance, [
        'amount' => 1000,
        'repaid_on' => now()->toDateString(),
        'method' => 'instapay',
    ]);

    // The clamp turned this into `cash` and told nobody.
    expect($repayment->fresh()->method)->toBe('instapay');

    $entry = app(LedgerPoster::class)->post($repayment->fresh());

    expect($entry->lines->firstWhere('debit', '>', 0)->ledger_account_id)->toBe($this->clearing->id);
});

it('still refuses a rail nobody activated, on both columns', function () {
    // The control. Both columns were UNENFORCED before this — anything saved cleanly — so a test
    // that only proved `instapay` works would pass against a column that accepts everything.
    expect(fn () => EmployeeAdvance::create([
        'employee_id' => $this->employee->id,
        'asset_id' => $this->asset->id,
        'type' => 'advance',
        'amount' => 100,
        'advance_date' => now()->toDateString(),
        'paid_from' => 'carrier_pigeon',
    ]))->toThrow(DomainException::class);
});
