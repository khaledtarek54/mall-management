<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\ExpenseService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
});

function makeExpense(array $attrs = []): Expense
{
    return Expense::create(array_merge([
        'asset_id' => makeAsset()->id,
        'category' => 'utilities',
        'amount' => 1000,
        'vat_amount' => 140,
        'paid_from' => 'cash',
        'expense_date' => now()->toDateString(),
        'status' => 'recorded',
    ], $attrs));
}

it('enforces total = amount + vat on write (even with no total provided)', function () {
    $expense = makeExpense(); // no total passed

    expect((float) $expense->fresh()->total)->toEqualWithDelta(1140.0, 0.001);
});

it('coerces blank money strings without crashing (decimal cast)', function () {
    $expense = makeExpense(['amount' => '', 'vat_amount' => '']);

    expect((float) $expense->fresh()->amount)->toEqualWithDelta(0.0, 0.001);
    expect((float) $expense->fresh()->total)->toEqualWithDelta(0.0, 0.001);
});

it('journalizes a cash expense as Dr expense + Dr VAT-recoverable / Cr cash', function () {
    $expense = makeExpense(['category' => 'utilities', 'paid_from' => 'cash']);

    $entry = $this->poster->post($expense->fresh());

    expect($entry->isBalanced())->toBeTrue();
    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('utilities_expense')]->debit)->toEqualWithDelta(1000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('vat_recoverable')]->debit)->toEqualWithDelta(140.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('cash')]->credit)->toEqualWithDelta(1140.0, 0.001);
});

it('credits the bank when paid_from is bank', function () {
    $expense = makeExpense(['paid_from' => 'bank']);

    $entry = $this->poster->post($expense->fresh());

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect($byAccount->has($this->accounts->id('bank')))->toBeTrue();
    expect($byAccount->has($this->accounts->id('cash')))->toBeFalse();
});

it('skips journalizing a cancelled expense', function () {
    expect($this->poster->post(makeExpense(['status' => 'cancelled'])))->toBeNull();
});

it('keeps the trial balance balanced after posting an expense', function () {
    $this->poster->post(makeExpense());

    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('books each category to its expense account, and an unmapped one to admin with a warning', function () {
    // A mapped category → its dedicated expense account.
    $marketing = $this->poster->post(makeExpense(['category' => 'marketing'])->fresh());
    expect($marketing->lines->keyBy('ledger_account_id')->has($this->accounts->id('marketing_expense')))->toBeTrue();

    // A category the FLOOR MAP does not name → admin_expense + a warning (MapsExpenseCategory).
    //
    // `insurance` has to be a real, ACTIVE catalogue row now: `expenses.category` gained a value set
    // with EG-13, so an arbitrary string is refused at save and this case died on the fixture rather
    // than on the behaviour it describes. It ships INACTIVE, which is the operator's decision to
    // make, so the test makes it — and leaves `ledger_account_id` null, which is precisely the
    // situation the warning exists for: a category nobody has pointed at an account.
    $this->seed(ExpenseCategorySeeder::class);
    ExpenseCategory::query()->where('code', 'insurance')->firstOrFail()
        ->update(['is_active' => true, 'ledger_account_id' => null]);

    Log::spy();
    $unmapped = $this->poster->post(makeExpense(['category' => 'insurance'])->fresh());
    expect($unmapped->lines->keyBy('ledger_account_id')->has($this->accounts->id('admin_expense')))->toBeTrue();
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('cancels a recorded expense (idempotent) via the service', function () {
    $expense = makeExpense();

    app(ExpenseService::class)->cancel($expense);
    expect($expense->fresh()->status)->toBe('cancelled');

    app(ExpenseService::class)->cancel($expense->fresh()); // idempotent, no throw
    expect($expense->fresh()->status)->toBe('cancelled');
});

it('omits the VAT-recoverable line for a VAT-free expense', function () {
    $entry = $this->poster->post(makeExpense(['amount' => 1000, 'vat_amount' => 0, 'total' => 1000])->fresh());
    $byAccount = $entry->lines->keyBy('ledger_account_id');

    expect($entry->isBalanced())->toBeTrue();
    expect($byAccount->has($this->accounts->id('vat_recoverable')))->toBeFalse();
});
