<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\GenerateRecurringExpensesService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ExpenseCategorySeeder;

/**
 * A PERIOD IS A MONTH, NOT A DATE — moving the booking day must not re-book the period.
 *
 * `RecurringExpense::nextDueOn()` walks forward from `starts_on` in whole periods and skips the
 * ones already generated, which it decided by comparing the cursor against `last_generated_on` as a
 * plain DATE. So an operator who moved `day_of_month` from the 1st to the 15th — an ordinary edit,
 * because the money now leaves the bank later in the month — made every cursor in the series land
 * after the stamp: 15 September IS after 1 September. The nightly run booked September a second
 * time, for the full assessed amount, and posted it.
 *
 * **The UNIQUE index cannot catch this one.** `expenses.(recurring_expense_id, expense_date)` is
 * what makes generation safe to repeat, and here the two documents carry two different dates —
 * that is the entire content of the edit. So the belt held and the braces were never reached.
 *
 * Every frequency this model offers spans at least a whole month, so a calendar month holds at most
 * one period and the month IS the period's identity. Comparing months makes the day a statement
 * about WHEN inside the period a cost books rather than WHETHER it books at all.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);
    ExpenseCategory::where('code', 'government_fees')->update(['is_active' => true]);
    ExpenseCategory::flushCatalogue();

    $this->asset = makeAsset(['code' => 'DAY']);

    $this->schedule = RecurringExpense::create([
        'asset_id' => $this->asset->id,
        'description' => 'Municipal levy',
        'category' => 'government_fees',
        'amount' => 12000,
        'frequency' => RecurringExpense::MONTHLY,
        'day_of_month' => 1,
        'starts_on' => '2026-07-01',
    ]);
});

it('does not book the period again when the day moves later within it', function () {
    $run = app(GenerateRecurringExpensesService::class);

    // Two ordinary months on the 1st.
    $run->generate(CarbonImmutable::parse('2026-07-05'));
    $run->generate(CarbonImmutable::parse('2026-08-05'));

    expect(Expense::where('recurring_expense_id', $this->schedule->id)->count())->toBe(2);

    // The operator moves the booking day; the money now leaves the bank mid-month.
    $this->schedule->update(['day_of_month' => 15]);

    // The very next nightly run, still inside August.
    $result = $run->generate(CarbonImmutable::parse('2026-08-20'));

    expect($result['generated'])->toBe(0)
        ->and(Expense::where('recurring_expense_id', $this->schedule->id)->count())->toBe(2)
        ->and(Expense::where('recurring_expense_id', $this->schedule->id)->sum('amount'))
        ->toEqual(24000.0);
});

it('still books the NEXT period on the new day — the edit is honoured, not ignored', function () {
    // The control. A guard that simply stopped generating would satisfy the refusal above and
    // silently end the schedule, which is the worse failure of the two.
    $run = app(GenerateRecurringExpensesService::class);

    $run->generate(CarbonImmutable::parse('2026-07-05'));
    $this->schedule->update(['day_of_month' => 15]);

    $run->generate(CarbonImmutable::parse('2026-08-20'));

    $august = Expense::where('recurring_expense_id', $this->schedule->id)
        ->orderByDesc('expense_date')->first();

    expect(Expense::where('recurring_expense_id', $this->schedule->id)->count())->toBe(2)
        ->and($august->expense_date->toDateString())->toBe('2026-08-15');
});

it('does not re-book a SEMIANNUAL instalment either — the frequency the premise rests on', function () {
    // Every other case here is monthly, and the docblock's load-bearing claim is that EVERY
    // frequency spans at least a whole month. A semiannual schedule is also the more alarming form
    // of the original bug: it is the real-estate tax instalment EG-33 was built for, and booking it
    // twice is 48,000 of statutory cost posted against a levy that was assessed once.
    $asset = makeAsset(['code' => 'TAX']);

    $schedule = RecurringExpense::create([
        'asset_id' => $asset->id,
        'description' => 'Real-estate tax instalment',
        'category' => 'government_fees',
        'amount' => 48000,
        'frequency' => RecurringExpense::SEMIANNUALLY,
        'day_of_month' => 1,
        'starts_on' => '2026-03-01',
    ]);

    $run = app(GenerateRecurringExpensesService::class);
    // Scoped to this schedule's own property: `generate()` sweeps the portfolio, and the monthly
    // schedule from `beforeEach` would otherwise be counted in `generated`.
    $run->generate(CarbonImmutable::parse('2026-03-04'), $asset->id);

    expect(Expense::where('recurring_expense_id', $schedule->id)->count())->toBe(1);

    $schedule->update(['day_of_month' => 15]);

    $result = $run->generate(CarbonImmutable::parse('2026-03-20'), $asset->id);

    expect($result['generated'])->toBe(0)
        ->and(Expense::where('recurring_expense_id', $schedule->id)->count())->toBe(1)
        // …and September, the next instalment, still books on the new day.
        ->and($run->generate(CarbonImmutable::parse('2026-09-20'), $asset->id)['generated'])->toBe(1)
        ->and(Expense::where('recurring_expense_id', $schedule->id)
            ->orderByDesc('expense_date')->first()->expense_date->toDateString())
        ->toBe('2026-09-15');
});

it('does not re-raise a supplier BILL either — the other half of the same walk', function () {
    // A schedule naming a vendor raises a DRAFT `VendorBill` instead of an expense, off the SAME
    // `nextDueOn()`, and `vendor_bills.(recurring_expense_id, bill_date)` has the identical blind
    // spot. The failure is quieter — a duplicate bill is `draft` and posts nothing until somebody
    // approves it — but a second claim from a supplier who sent one invoice is still a payable
    // waiting to be paid twice.
    $vendor = Vendor::factory()->create();

    $asset = makeAsset(['code' => 'LIFT']);

    $schedule = RecurringExpense::create([
        'asset_id' => $asset->id,
        'vendor_id' => $vendor->id,
        'description' => 'Lift maintenance retainer',
        'category' => 'government_fees',
        'amount' => 9000,
        'frequency' => RecurringExpense::MONTHLY,
        'day_of_month' => 1,
        'starts_on' => '2026-07-01',
        'payment_terms_days' => 30,
    ]);

    $run = app(GenerateRecurringExpensesService::class);
    $run->generate(CarbonImmutable::parse('2026-07-05'), $asset->id);

    expect(VendorBill::where('recurring_expense_id', $schedule->id)->count())->toBe(1);

    $schedule->update(['day_of_month' => 15]);

    expect($run->generate(CarbonImmutable::parse('2026-07-20'), $asset->id)['generated'])->toBe(0)
        ->and(VendorBill::where('recurring_expense_id', $schedule->id)->count())->toBe(1);
});

it('leaves the catch-up direction alone — a schedule switched back on still books one period', function () {
    // The month comparison must not swallow a period the schedule genuinely owes. July is booked,
    // the schedule sleeps through August, and the run on 3 September books AUGUST — one period per
    // run, the pace EG-33 chose deliberately.
    $run = app(GenerateRecurringExpensesService::class);
    $run->generate(CarbonImmutable::parse('2026-07-05'));

    $result = $run->generate(CarbonImmutable::parse('2026-09-03'));

    expect($result['generated'])->toBe(1)
        ->and(Expense::where('recurring_expense_id', $this->schedule->id)
            ->orderByDesc('expense_date')->first()->expense_date->toDateString())
        ->toBe('2026-08-01');
});
