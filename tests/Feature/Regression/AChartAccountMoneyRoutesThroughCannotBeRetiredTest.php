<?php

/**
 * Retiring a chart account is not free while a bank account, a payment rail or an expense
 * category is still routed to it.
 *
 * `MoneyAccount::ledgerAccountOf()`, `PaymentMethod::accountIdOrFloor()` and
 * `ExpenseCategory::accountIdOrFloor()` each re-ask `is_postable && is_active` at POSTING time and
 * fall through to the generic `bank`/`cash` floor when the answer is no. That fall-through is
 * right for the document being posted and STAYS — it keeps the entry postable rather than killing
 * the sync job.
 *
 * It is wrong for HISTORY. Accounts resolve at payload time and `LedgerPoster::matches()` compares
 * `ledger_account_id`, so the weekly `accounting:sync-ledger --all` sweep re-homes every entry ever
 * posted through the account onto the floor — and `MatchBankStatementLineService::candidatesFor()`
 * selects candidates by exactly that column, so the bank's own statements stop finding their own
 * postings and every match already made points at a voided line. In a closed period the re-post is
 * refused outright, which leaves permanent drift on `billing:reconcile --deep`.
 *
 * The sharp edge is that `#[DeletableWhenUnused]` on `LedgerAccount` tells the operator to
 * "deactivate the account" instead of deleting it — so the escape the deletion guard named was
 * itself the destructive act.
 */

use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;

/** A postable, active leaf under the bank branch — the shape a real bank account points at. */
function ledgerLeafForRoutingTest(string $code, string $type = 'asset'): LedgerAccount
{
    return LedgerAccount::create([
        'code' => $code,
        'name_en' => 'Leaf '.$code,
        'name_ar' => 'حساب '.$code,
        'type' => $type,
        'is_postable' => true,
        'is_active' => true,
    ]);
}

it('refuses to retire an account a bank account still banks through, and names it', function () {
    $leaf = ledgerLeafForRoutingTest('11102002');

    BankAccount::create([
        'asset_id' => makeAsset()->id,
        'name' => 'CIB Operating',
        'bank_name' => 'CIB',
        'account_number' => '1234567890',
        'currency' => 'EGP',
        'purpose' => BankAccount::PURPOSE_OPERATING,
        'ledger_account_id' => $leaf->id,
        'is_active' => true,
    ]);

    expect(fn () => $leaf->update(['is_active' => false]))
        ->toThrow(DomainException::class, 'CIB Operating');

    // Refused means refused: the row is untouched, not saved-then-complained-about.
    expect($leaf->fresh()->is_active)->toBeTrue();
});

it('refuses to turn a routed account into a summary parent, which does the same damage', function () {
    // `is_postable` is the other half of the same predicate every tier re-asks at posting time —
    // an account made into a rollup parent falls through to the floor exactly as a retired one does.
    $leaf = ledgerLeafForRoutingTest('11102003');

    BankAccount::create([
        'asset_id' => makeAsset()->id,
        'name' => 'NBE Operating',
        'bank_name' => 'NBE',
        'account_number' => '2233445566',
        'currency' => 'EGP',
        'purpose' => BankAccount::PURPOSE_OPERATING,
        'ledger_account_id' => $leaf->id,
        'is_active' => true,
    ]);

    expect(fn () => $leaf->update(['is_postable' => false]))->toThrow(DomainException::class);
});

it('refuses for a payment rail and for an expense category too', function () {
    $railLeaf = ledgerLeafForRoutingTest('11102004');
    $costLeaf = ledgerLeafForRoutingTest('51109001', 'expense');

    PaymentMethod::create([
        'code' => 'fawry',
        'name_en' => 'Fawry',
        'name_ar' => 'فوري',
        'ledger_account_id' => $railLeaf->id,
        'for_inbound' => true,
        'for_outbound' => false,
        'is_active' => true,
    ]);

    ExpenseCategory::create([
        'code' => 'insurance',
        'name_en' => 'Insurance',
        'name_ar' => 'التأمين',
        'ledger_account_id' => $costLeaf->id,
        'cost_nature' => 'variable',
        'is_active' => true,
    ]);

    expect(fn () => $railLeaf->update(['is_active' => false]))
        ->toThrow(DomainException::class, 'Fawry')
        ->and(fn () => $costLeaf->update(['is_active' => false]))
        ->toThrow(DomainException::class, 'Insurance');
});

it('lets a routed account be renamed, and lets an unrouted one be retired', function () {
    // Both controls, and both matter. Refusing every edit to a referenced account would make the
    // chart uneditable — the trap already on record for `#[NeverDeletable]`, through another door —
    // and refusing to retire an account nothing routes to would break the ordinary way an operator
    // tidies a chart.
    $routed = ledgerLeafForRoutingTest('11102005');
    $free = ledgerLeafForRoutingTest('11102006');

    BankAccount::create([
        'asset_id' => makeAsset()->id,
        'name' => 'HSBC Operating',
        'bank_name' => 'HSBC',
        'account_number' => '9988776655',
        'currency' => 'EGP',
        'purpose' => BankAccount::PURPOSE_OPERATING,
        'ledger_account_id' => $routed->id,
        'is_active' => true,
    ]);

    $routed->update(['name_en' => 'Renamed while still banked through']);
    $free->update(['is_active' => false]);

    expect($routed->fresh()->name_en)->toBe('Renamed while still banked through')
        ->and($free->fresh()->is_active)->toBeFalse();
});
