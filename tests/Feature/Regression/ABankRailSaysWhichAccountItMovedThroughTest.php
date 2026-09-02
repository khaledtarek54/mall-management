<?php

use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Models\BankAccount;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Payroll;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * **Naming the bank account is part of recording money on a bank rail — asked, defaulted, required.**
 *
 * EG-12 (2026-08-22) gave six money documents a `bank_account_id` and made `App\Support\MoneyAccount`
 * the one resolver. It left the field OPTIONAL on every form with no default, so on a real install
 * almost every document named nothing, every posting fell to the generic `bank` role, and the
 * two-bank separation the feature exists for stayed theoretical.
 *
 * Yardi makes the cash account mandatory on every money movement, and it is liveable there for one
 * reason: the PROPERTY carries default cash accounts, so a receipt arrives with its bank filled in
 * and the operator confirms rather than chooses. Required without a default is the worst half of
 * that design — somebody picking the same value three hundred times a month eventually picks the
 * wrong one, and a wrong bank account is worse than none, because
 * `MatchBankStatementLineService::candidatesFor()` finds candidates BY the chart account and would
 * present the mistake as a real match against the wrong statement.
 *
 * So the three halves are tested together, and each is paired with its control.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(PaymentMethodSeeder::class);

    $this->asset = makeAsset(['code' => 'BR']);
    $this->other = makeAsset(['code' => 'BX']);

    $this->operating = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'CIB — operating',
        'account_number' => 'BR-OP-0001',
        'purpose' => BankAccount::PURPOSE_OPERATING,
        'is_default' => true,
    ]);
});

/**
 * A rail with no row falls to `code !== 'cash'` — the SAME ternary `accountIdOrFloor()` applies, so
 * the form asks about exactly the money the posting engine is going to book to the `bank` role.
 *
 * That fall-through is load-bearing rather than tidy: `payrolls.paid_from`,
 * `expenses.paid_from` and `deposit_transactions.method` all accept the legacy literal **`bank`**,
 * which is a `ValueSets` member and has never been a `payment_methods` code. Reading "no row" as
 * "no requirement" would exempt the single most obviously bank-borne value in the system.
 */
it('takes the rail catalogue as the answer, and the posting floor when there is no row', function () {
    expect(PaymentMethod::requiresBankAccount('bank_transfer'))->toBeTrue()
        ->and(PaymentMethod::requiresBankAccount('cash'))->toBeFalse()
        ->and(PaymentMethod::requiresBankAccount('bank'))->toBeTrue()
        ->and(PaymentMethod::requiresBankAccount('some_rail_nobody_seeded'))->toBeTrue()
        // An unanswered rail must not demand an account: on the one form where the rail is optional
        // the placeholder says a blank means cash.
        ->and(PaymentMethod::requiresBankAccount(null))->toBeFalse();
});

/**
 * The operator's row beats the floor IN BOTH DIRECTIONS — and the falsy one is the half a `??`
 * lookup silently gets wrong, because "the operator said no" and "there is no row" are both falsy.
 */
it('lets a rail row overrule the floor in both directions', function () {
    PaymentMethod::query()->where('code', 'instapay')->update(['requires_bank_account' => false]);
    PaymentMethod::query()->where('code', 'cash')->update(['requires_bank_account' => true]);
    app()->forgetInstance('payment_method.needs_bank');

    expect(PaymentMethod::requiresBankAccount('instapay'))->toBeFalse()
        ->and(PaymentMethod::requiresBankAccount('cash'))->toBeTrue();
});

/** Rung 1 and rung 2 of the ladder: this purpose, else the operating account. */
it('defaults a document to its own purpose, and to operating when there is none', function () {
    $deposits = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'NBE — tenant deposits',
        'account_number' => 'BR-DEP-0001',
        'purpose' => BankAccount::PURPOSE_DEPOSITS,
        'is_default' => true,
    ]);

    expect(BankAccount::defaultFor($this->asset->id, BankAccount::PURPOSE_DEPOSITS)?->id)->toBe($deposits->id)
        ->and(BankAccount::defaultFor($this->asset->id, BankAccount::PURPOSE_OPERATING)?->id)->toBe($this->operating->id)
        // No payroll account: salaries leave the operating account, which is what a mall without a
        // dedicated salary account actually does.
        ->and(BankAccount::defaultFor($this->asset->id, BankAccount::PURPOSE_PAYROLL)?->id)->toBe($this->operating->id);
});

/**
 * Rung 3 and rung 4. One account is not a choice; two accounts and no default IS one, and guessing
 * between them is how money lands in the wrong bank.
 */
it('answers with the only account there is, and refuses to guess between two', function () {
    $lonely = BankAccount::create([
        'asset_id' => $this->other->id,
        'name' => 'Sole account',
        'account_number' => 'BX-0001',
        'purpose' => BankAccount::PURPOSE_OPERATING,
        'is_default' => false,
    ]);

    expect(BankAccount::defaultFor($this->other->id, BankAccount::PURPOSE_OPERATING)?->id)->toBe($lonely->id);

    BankAccount::create([
        'asset_id' => $this->other->id,
        'name' => 'Second account',
        'account_number' => 'BX-0002',
        'purpose' => BankAccount::PURPOSE_OPERATING,
        'is_default' => false,
    ]);

    expect(BankAccount::defaultFor($this->other->id, BankAccount::PURPOSE_OPERATING))->toBeNull()
        // And a property that banks nowhere keeps naming nothing, so `MoneyAccount` falls to the
        // rail and then the posting role — verbatim what every install did before this.
        ->and(BankAccount::defaultFor(null, BankAccount::PURPOSE_OPERATING))->toBeNull();
});

/** Flagging a default demotes the previous holder — of the SAME purpose, and only that one. */
it('keeps one default per property per purpose', function () {
    $deposits = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'Deposits',
        'account_number' => 'BR-DEP-0002',
        'purpose' => BankAccount::PURPOSE_DEPOSITS,
        'is_default' => true,
    ]);

    $second = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'NBE — operating',
        'account_number' => 'BR-OP-0002',
        'purpose' => BankAccount::PURPOSE_OPERATING,
        'is_default' => true,
    ]);

    expect($this->operating->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        // The deposits account answers a different question and both are the default for theirs.
        ->and($deposits->fresh()->is_default)->toBeTrue();
});

/**
 * The document fills itself in — on CREATE, on a bank rail, and to the account its own PURPOSE
 * names. Each assertion is paired with the case that must NOT be filled.
 */
it('fills a new document in from the property default, and only where it should', function () {
    $deposits = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'Deposits',
        'account_number' => 'BR-DEP-0003',
        'purpose' => BankAccount::PURPOSE_DEPOSITS,
        'is_default' => true,
    ]);

    $lease = makeLease(makeUnit($this->asset), makeTenant());

    $onBank = Expense::create([
        'asset_id' => $this->asset->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Generator service',
        'category' => 'maintenance',
        'amount' => 1000,
        'paid_from' => 'bank',
    ]);

    $inCash = Expense::create([
        'asset_id' => $this->asset->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Petty cash cleaning supplies',
        'category' => 'maintenance',
        'amount' => 60,
        'paid_from' => 'cash',
    ]);

    $receipt = DepositTransaction::create([
        'lease_id' => $lease->id,
        'type' => 'receipt',
        'amount' => 5000,
        'transaction_date' => now()->toDateString(),
        'method' => 'bank',
        'status' => 'recorded',
    ]);

    expect($onBank->bank_account_id)->toBe($this->operating->id)
        // A cash expense never acquires a bank account it did not move through.
        ->and($inCash->bank_account_id)->toBeNull()
        // A deposit is money the operator HOLDS, so it banks where deposits bank — not with the
        // rent receipt beside it. This is the purpose ladder doing visible work.
        ->and($receipt->bank_account_id)->toBe($deposits->id);
});

/**
 * Never onto a document that already exists.
 *
 * `bank_account_id` is classified DERIVED, so writing one onto a committed document would make
 * `LedgerPoster::sync()` void its posted entry and re-post it to a different cash account. A
 * default that reached back over history would be a silent restatement of the books.
 */
it('never writes a default onto a document that already exists', function () {
    $expense = Expense::create([
        'asset_id' => $this->asset->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Booked before anyone flagged a default',
        'category' => 'maintenance',
        'amount' => 200,
        'paid_from' => 'cash',
    ]);

    expect($expense->bank_account_id)->toBeNull();

    $expense->update(['paid_from' => 'bank']);

    expect($expense->fresh()->bank_account_id)->toBeNull();
});

/**
 * And the form refuses, through the REAL page.
 *
 * The refusal is paired with two controls — the same page saving on a cash rail, and saving on a
 * bank rail once an account is named — because a form that refused everything would satisfy the
 * first assertion alone and read as a pass.
 */
it('refuses a bank-rail document that names no account', function () {
    $this->actingAs(makeUser('super_admin'));

    $fill = fn (string $rail, ?int $account) => [
        'asset_id' => $this->asset->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Lift maintenance retainer',
        'category' => 'maintenance',
        'amount' => 5000,
        'paid_from' => $rail,
        'bank_account_id' => $account,
    ];

    asTenant($this->asset, function () use ($fill) {
        Livewire::test(CreateExpense::class)
            ->fillForm($fill('bank_transfer', null))
            ->call('create')
            ->assertHasFormErrors(['bank_account_id']);

        Livewire::test(CreateExpense::class)
            ->fillForm($fill('cash', null))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateExpense::class)
            ->fillForm($fill('bank_transfer', $this->operating->id))
            ->call('create')
            ->assertHasNoFormErrors();
    });
});

/**
 * The requirement LIFTS where the register is empty.
 *
 * An install that has not reached the bank-account screen must still be able to record money — a
 * required field the picker cannot fill is a form that can only refuse. `ConfigurationHealth`
 * raises that as the advisory it is instead.
 */
it('does not require an account the register cannot offer', function () {
    $this->actingAs(makeUser('super_admin'));

    asTenant($this->other, function () {
        Livewire::test(CreateExpense::class)
            ->fillForm([
                'asset_id' => $this->other->id,
                'expense_date' => now()->toDateString(),
                'description' => 'Municipal levy',
                'category' => 'maintenance',
                'amount' => 900,
                'paid_from' => 'bank_transfer',
                'bank_account_id' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    });
});

/** The property guard is unchanged, and the default must never be the thing that trips it. */
it('still refuses another mall\'s account', function () {
    $foreign = BankAccount::create([
        'asset_id' => $this->other->id,
        'name' => 'Other mall account',
        'account_number' => 'BX-9999',
        'purpose' => BankAccount::PURPOSE_OPERATING,
    ]);

    expect(fn () => Expense::create([
        'asset_id' => $this->asset->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Wrong mall',
        'category' => 'maintenance',
        'amount' => 100,
        'paid_from' => 'bank',
        'bank_account_id' => $foreign->id,
    ]))->toThrow(DomainException::class);
});

/** Payroll follows its own purpose, which on a mall without a salary account is operating. */
it('banks a payroll run where salaries leave from', function () {
    $payroll = Payroll::create([
        'number' => 'PAY-BR-000001',
        'asset_id' => $this->asset->id,
        'period_month' => now()->startOfMonth(),
        'description' => 'Monthly payroll',
        'paid_from' => 'bank',
        'status' => 'draft',
        'gross_salaries' => 0,
        'salary_tax' => 0,
        'social_insurance' => 0,
    ]);

    expect($payroll->bank_account_id)->toBe($this->operating->id)
        ->and(Payroll::bankAccountPurpose())->toBe(BankAccount::PURPOSE_PAYROLL)
        ->and(Payment::bankAccountPurpose())->toBe(BankAccount::PURPOSE_OPERATING)
        ->and(DepositTransaction::bankAccountPurpose())->toBe(BankAccount::PURPOSE_DEPOSITS);
});
