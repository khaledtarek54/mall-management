<?php

/*
|--------------------------------------------------------------------------
| An expense category books where its row says, and where it always did otherwise
|--------------------------------------------------------------------------
| The category was the ONLY thing deciding which P&L account a supplier bill hits, and it lived in a
| six-entry `private const` inside a journalizer trait. Insurance, government fees and licences,
| bank charges, legal and professional fees and generator fuel all fell past it into `admin_expense`
| behind a `Log::warning` — in an Egyptian mall, most of the overhead in one bucket.
|
| Two properties, and the second is the one that lets this ship on a Friday:
|   * a category with a row pointing at an account books THERE;
|   * a category with no row, or a row with no account, books exactly where it did before.
*/

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\Journalizers\Concerns\MapsExpenseCategory;
use App\Support\CostNature;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\ExpenseCategorySeeder;

beforeEach(function () {
    $this->seed(AccountingSeeder::class);
    $this->asset = makeAsset();
});

it('books a cost to the account its category names', function () {
    $this->seed(ExpenseCategorySeeder::class);

    // A P&L account the operator already has, standing in for the insurance account an accountant
    // would create. `insurance` ships INACTIVE, so activating it is the operator's act.
    $account = LedgerAccount::query()->where('type', 'expense')->where('is_postable', true)->first();

    $insurance = ExpenseCategory::query()->where('code', 'insurance')->firstOrFail();
    $insurance->update(['ledger_account_id' => $account->id, 'is_active' => true]);

    $resolved = ExpenseCategory::accountIdOrFloor('insurance', $this->asset->id, app(AccountResolver::class), 'admin_expense');

    expect($resolved)->toBe($account->id);

    // The control: a category with NO account still floors to the role map, so pointing one rail at
    // an account cannot move the others.
    $maintenance = ExpenseCategory::accountIdOrFloor('maintenance', $this->asset->id, app(AccountResolver::class), 'maintenance_expense');

    expect($maintenance)->toBe(app(AccountResolver::class)->id('maintenance_expense', $this->asset->id))
        ->and($maintenance)->not->toBe($account->id);
});

it('posts identically on a database that has never seen the catalogue', function () {
    // No ExpenseCategorySeeder here, deliberately: this is an upgraded install between `migrate`
    // and the seeder, and the whole behaviour-identical claim rests on it.
    expect(ExpenseCategory::count())->toBe(0);

    // Driven through the JOURNALIZER, not through `accountIdOrFloor()` with the role handed in.
    //
    // My first version did the latter, and it could not fail: passing `maintenance_expense` in as an
    // argument and asserting the answer is `maintenance_expense` tests nothing about the map that
    // decides it — deleting an entry from `MapsExpenseCategory::EXPENSE_ROLE` left it green. The map
    // is the thing this case exists to protect, so the case has to go through the code that reads it.
    $resolver = app(AccountResolver::class);
    $journalizer = new class
    {
        use MapsExpenseCategory;

        public function resolve(string $category, ?int $assetId, AccountResolver $accounts): int
        {
            return $this->expenseAccountIdFor($category, $assetId, $accounts, 'probe');
        }
    };

    foreach ([
        'maintenance' => 'maintenance_expense',
        'utilities' => 'utilities_expense',
        'cleaning_security' => 'cleaning_security_expense',
        'marketing' => 'marketing_expense',
        'admin' => 'admin_expense',
        'other' => 'admin_expense',
    ] as $code => $role) {
        expect($journalizer->resolve($code, $this->asset->id, $resolver))
            ->toBe($resolver->id($role, $this->asset->id), "Category '{$code}' moved off its floor account.");
    }

    // The control: the six accounts are not all the same one, so the loop above is comparing
    // distinct values rather than agreeing with itself six times.
    expect($resolver->id('maintenance_expense', $this->asset->id))
        ->not->toBe($resolver->id('utilities_expense', $this->asset->id));
});

it('lets a row say a cost is fixed, in both directions', function () {
    $this->seed(ExpenseCategorySeeder::class);

    // `insurance` is not in `CostNature::MAP`, so before the catalogue it answered `variable` and
    // was apportioned through the CAM pool as though it moved with occupancy. It does not.
    expect(CostNature::forCategory('insurance'))->toBe(CostNature::FIXED)
        // …and the REVERSE direction agrees. Reading only the const here would have let a category
        // answer `fixed` one way and be absent the other, so a pool filtered by nature would omit a
        // cost that was itself classified correctly — the drift the method's docblock forbids.
        ->and(CostNature::categoriesOf(CostNature::FIXED))->toContain('insurance');

    // The control: a variable one is in neither.
    expect(CostNature::forCategory('bank_charges'))->toBe(CostNature::VARIABLE)
        ->and(CostNature::categoriesOf(CostNature::FIXED))->not->toContain('bank_charges');
});

it('refuses a category that is in neither the floor nor the catalogue', function () {
    $this->seed(ExpenseCategorySeeder::class);

    // The column had NO value set at all before this — a typo'd or imported category saved cleanly
    // and then silently booked to admin_expense.
    expect(fn () => Expense::create([
        'asset_id' => $this->asset->id,
        'number' => 'EXP-TEST-1',
        'category' => 'nonsense',
        'paid_from' => 'cash',
        'status' => 'recorded',
        'amount' => 100,
        'expense_date' => '2026-03-01',
    ]))->toThrow(DomainException::class);
});
