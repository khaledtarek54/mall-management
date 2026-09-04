<?php

/*
|--------------------------------------------------------------------------
| A recurring cost may not book a period before the schedule begins (SW-086)
|--------------------------------------------------------------------------
| `RecurringExpense::nextDueOn()` put the schedule's own `day_of_month` inside the MONTH of
| `starts_on`. `day_of_month` DEFAULTS to 1 on the form and in the column, and `starts_on` is
| required with no default, so the ordinary act of setting a schedule up mid-month produced a first
| document dated the 1st — before the operator said the cost begins, for a period that had not
| started.
|
| Measured at HEAD: `starts_on 2026-09-20`, `day_of_month 1`, monthly, asked on 2026-09-25 →
| 2026-09-01. Annually, the same shape → 2026-09-01 and then 2027-09-01, so the series ran
| nineteen days early for the rest of its life.
|
| The rule is the one the field's own help states — "the first period booked… earlier periods are
| never back-filled" — read strictly: the first scheduled day ON OR AFTER `starts_on`. It is also
| the conservative direction for money going out, and the operator has two visible escapes: move
| `day_of_month`, or move `starts_on`. Both are on the same form, and the register's "Next due"
| column shows the answer before the first run.
*/

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Services\GenerateRecurringExpensesService;
use Carbon\CarbonImmutable;
use Database\Seeders\ExpenseCategorySeeder;

beforeEach(function () {
    // `government_fees` ships deliberately switched OFF; activated here exactly as the operator
    // would before their first levy.
    $this->seed(ExpenseCategorySeeder::class);
    ExpenseCategory::where('code', 'government_fees')->update(['is_active' => true]);
    ExpenseCategory::flushCatalogue();

    $this->asset = makeAsset(['code' => 'SB']);
});

/** A municipal levy that BEGINS on the 20th while its booking day is the 1st — the shape at issue. */
function levySchedule(array $attributes = []): RecurringExpense
{
    return RecurringExpense::create($attributes + [
        'asset_id' => test()->asset->id,
        'description' => 'Municipal levy',
        'category' => 'government_fees',
        'amount' => 12000,
        'frequency' => RecurringExpense::MONTHLY,
        'day_of_month' => 1,
        'starts_on' => '2026-09-20',
    ]);
}

it('books nothing in the month it starts when its own day has already passed', function () {
    $schedule = levySchedule();

    // Five days after the schedule begins, and the ONLY September date it could have chosen is the
    // 1st — nineteen days before the operator said this cost starts.
    expect($schedule->nextDueOn(CarbonImmutable::parse('2026-09-25')))->toBeNull();

    // The real path, not just the arithmetic: the run must mint nothing.
    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-09-25'));

    expect(Expense::where('recurring_expense_id', $schedule->id)->count())->toBe(0);
});

it('books the first period on the first scheduled day after it begins', function () {
    // The control, and the half that makes the refusal above a rule rather than a dead schedule.
    $schedule = levySchedule();

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-10-05'));

    $expense = Expense::where('recurring_expense_id', $schedule->id)->sole();

    expect($expense->expense_date->toDateString())->toBe('2026-10-01')
        ->and((float) $expense->amount)->toBe(12000.0)
        ->and($schedule->fresh()->last_generated_on->toDateString())->toBe('2026-10-01');
});

it('still books the very day a schedule that begins on its own day begins', function () {
    // The other control. Anything that shifts THIS forward would silently skip a period for every
    // schedule the system already runs — every fixture and every seeded schedule starts on the 1st
    // with `day_of_month` 1.
    $schedule = levySchedule(['starts_on' => '2026-09-01']);

    expect($schedule->nextDueOn(CarbonImmutable::parse('2026-09-25'))?->toDateString())->toBe('2026-09-01');

    // …and a day LATER in the month than `starts_on` is untouched: it was always after the start.
    $later = levySchedule(['starts_on' => '2026-09-20', 'day_of_month' => 25]);

    expect($later->nextDueOn(CarbonImmutable::parse('2026-09-30'))?->toDateString())->toBe('2026-09-25');
});

it('steps a WHOLE period, so a quarterly or annual schedule stays anchored to its starting month', function () {
    // A one-month step here would re-anchor the cadence: the Egyptian real-estate tax is payable in
    // two instalments a year, and moving them off their month is a filing question, not a cosmetic
    // one.
    $quarterly = levySchedule(['frequency' => RecurringExpense::QUARTERLY]);
    $annual = levySchedule(['frequency' => RecurringExpense::ANNUALLY]);

    expect($quarterly->nextDueOn(CarbonImmutable::parse('2026-12-31'))?->toDateString())->toBe('2026-12-01')
        ->and($annual->nextDueOn(CarbonImmutable::parse('2027-12-31'))?->toDateString())->toBe('2027-09-01');
});

it('leaves a schedule that has already generated exactly where it was', function () {
    // The stamp is read by MONTH (the SW-073 fix), and this must not disturb it: a schedule that
    // booked September under the old rule carries on into October rather than re-booking.
    $schedule = levySchedule();
    $schedule->forceFill(['last_generated_on' => '2026-09-01'])->save();

    expect($schedule->nextDueOn(CarbonImmutable::parse('2026-10-05'))?->toDateString())->toBe('2026-10-01');
});
