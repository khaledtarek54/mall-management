<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\RecurringExpense;
use App\Support\ValueSets;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ExpenseCategorySeeder;

/**
 * RETIRING A CATALOGUE CODE TOOK IT OUT OF THE SET THE COLUMN MAY HOLD (SW-206).
 *
 * `ValueSets` is the replacement for the DB enum — it answers *what may this column hold* — and it
 * widened from `IsCodeCatalogue::codes()`, which was `is_active = true` only. So retiring an
 * operator-added code removed it from the ACCEPTED set, not merely from the OFFERED one, and every
 * path that re-states the column was refused at the model layer. A plain edit survived by accident:
 * `guard()` short-circuits on `! isDirty()`.
 *
 * The harm is not theoretical and it is not a screen. `ExpenseCategorySeeder` ships five Egyptian
 * overheads switched OFF — insurance, government fees, bank charges, legal and professional, fuel —
 * so "activate it, file costs under it, retire it" is the ordinary life of a row here. Retire one a
 * `recurring_expenses` schedule names and `expenses:generate-recurring` refuses that schedule for
 * ever: `Expense::create(['category' => $schedule->category, …])` makes the column dirty, `guard()`
 * no longer finds the code, and the refusal is caught per schedule and counted as a failure — so
 * the command exits non-zero every night and the levy never books again. That is verbatim the
 * failure `GenerateRecurringExpensesService`'s own docblock says EG-33 exists to prevent.
 *
 * A catalogue answers TWO questions and already had two methods: `options()` is what may be filed
 * NOW, `filterOptions()` is what may already be filed. `ValueSets` asks the second one and was
 * calling the first. Only the widening moved; `is_active` still decides every picker.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    $this->asset = makeAsset();
});

it('goes on booking a recurring cost whose category the operator has retired', function () {
    // The operator switches `insurance` on — it SHIPS off, which is what makes this the ordinary
    // case rather than an exotic one — and files a schedule under it.
    ExpenseCategory::query()->where('code', 'insurance')->sole()->update(['is_active' => true]);

    $schedule = RecurringExpense::create([
        'asset_id' => $this->asset->id,
        'category' => 'insurance',
        'description' => 'Building insurance premium',
        'amount' => 18000,
        'frequency' => 'monthly',
        'day_of_month' => 1,
        'starts_on' => CarbonImmutable::now()->subMonths(2)->startOfMonth()->toDateString(),
        'is_active' => true,
    ]);

    // Months later the accountant retires it again — a deliberate act, with a row behind it.
    ExpenseCategory::query()->where('code', 'insurance')->sole()->update(['is_active' => false]);

    // The premise, asserted rather than assumed: the picker really has dropped it, so this case is
    // about a RETIRED code and not about an active one.
    expect(ExpenseCategory::options())->not->toHaveKey('insurance');

    $this->artisan('expenses:generate-recurring')->assertSuccessful();

    $expense = Expense::query()->where('recurring_expense_id', $schedule->id)->first();

    expect($expense)->not->toBeNull()
        ->and($expense->category)->toBe('insurance');
});

it('keeps the retired code out of every picker while the column still accepts it', function () {
    // The opposite direction, and the one that would make the fix worse than the bug: `is_active`
    // must go on deciding what may be filed NOW. Only what the column may HOLD changed.
    ExpenseCategory::query()->where('code', 'insurance')->sole()->update(['is_active' => true]);

    expect(ExpenseCategory::options())->toHaveKey('insurance');

    ExpenseCategory::query()->where('code', 'insurance')->sole()->update(['is_active' => false]);

    expect(ExpenseCategory::options())->not->toHaveKey('insurance')
        // …the column accepts it, on both derivations, or the picker and the listener disagree,
        // which is the 2026-08-18 deposit bug.
        ->and(ValueSets::forTable('expenses')['category'])->toContain('insurance')
        ->and(ValueSets::allowed('expenses', 'category'))->toContain('insurance')
        // …the FILTER still reaches the costs already filed under it…
        ->and(ExpenseCategory::filterOptions())->toHaveKey('insurance')
        // …and it still LABELS, so their history reads.
        ->and(ExpenseCategory::labelFor('insurance'))->toBe('Insurance');
});

it('still refuses a code no catalogue has ever named', function () {
    // THE CONTROL. A widening that accepted anything would satisfy the case above just as happily
    // and would delete the guard — the whole point of `ValueSets` is that it replaced a DB enum.
    expect(fn () => Expense::create([
        'asset_id' => $this->asset->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Filed under a category nobody ever created',
        'category' => 'unicorn_rides',
        'amount' => 100,
        'paid_from' => 'cash',
    ]))->toThrow(DomainException::class);
});

it('still refuses a rail whose direction the catalogue does not allow', function () {
    // The second control, on the other catalogue: widening is per COLUMN and the direction scope
    // must survive it. `payments.method` widens from the INBOUND rails, so an outbound-only code —
    // retired or not — must not become an acceptable way to be paid.
    $tenant = makeTenant();

    expect(fn () => Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 100,
        'method' => 'carrier_pigeon',
        'payment_date' => now()->toDateString(),
        'status' => 'captured',
    ]))->toThrow(DomainException::class);
});
