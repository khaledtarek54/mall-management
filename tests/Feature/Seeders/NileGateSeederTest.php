<?php

use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\Accounting\AccountResolver;
use App\Settings\AccountingSettings;
use App\Support\CashBalanceGuard;
use App\Support\MoneyAccount;
use Database\Seeders\LearningSeeder;
use Database\Seeders\NileGateSeeder;

/**
 * The staging SOAK seeder runs end to end on a reference install — UNDER THE CLIENT'S RULE, both
 * overdraft refusals ON, as they are on staging — and the property it seeds opens with a funded
 * treasury. It had no test at all until meeting 2026-09-02 point 16 made a cash box refuse to be
 * spent below what it holds: this seeder pays two petty expenses from cash and ~590k of bills,
 * payroll and assets from the bank, every one BEFORE its receipts post (the sweep at the end of
 * `run()` posts them), so with no opening balances the first of them is refused and the seed dies
 * mid-way — on a fresh soak box, the exact place nothing checks. ONE seed run, every assertion a
 * property of the same database (the `DemoSeederTest` rule).
 */
it('seeds the soak property end to end under the client\'s rule, with a treasury that covers what it spends', function () {
    app(AccountingSettings::class)->refresh()->fill(['refuse_overdrawn_cash' => true, 'refuse_overdrawn_bank' => true])->save();

    $this->seed(LearningSeeder::class);
    $this->seed(NileGateSeeder::class);

    $ng = Asset::query()->where('code', 'NG')->firstOrFail();
    $cash = MoneyAccount::for(null, 'cash', $ng->id, app(AccountResolver::class));
    $cib = BankAccount::query()->where('asset_id', $ng->id)->where('purpose', BankAccount::PURPOSE_OPERATING)->firstOrFail();

    expect($cash)->not->toBeNull();
    expect(Expense::query()->where('asset_id', $ng->id)->where('paid_from', 'cash')->count())->toBe(2);
    expect(Payment::query()->count())->toBeGreaterThan(0);
    // The float less the two cash expenses, and never below zero — the guard's own rule; the bank
    // ends above its opening because the receipts outweigh the outflows.
    expect(CashBalanceGuard::balanceOf($cash, $ng->id))->toBeGreaterThan(0)
        ->and(CashBalanceGuard::balanceOf($cash, $ng->id))->toBeLessThan(10000)
        ->and(CashBalanceGuard::balanceOf((int) $cib->ledger_account_id, null, whole: true))->toBeGreaterThan(750000);
});
