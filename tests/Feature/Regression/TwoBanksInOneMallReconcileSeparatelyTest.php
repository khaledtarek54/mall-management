<?php

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Banking\MatchBankStatementLineService;
use App\Services\VendorBillService;
use App\Support\MoneyAccount;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * **A mall banking in two places must be able to reconcile either one.** — EG-12
 *
 * `bank_accounts` shipped on 2026-08-11 carrying a `ledger_account_id` that names the chart account
 * it IS, and **no journalizer ever read it**. Every posting resolved the `bank` ROLE — one account
 * per property — so both banks' money landed in the same chart account.
 *
 * That is not cosmetic. `MatchBankStatementLineService::candidatesFor()` finds candidates with
 * `where('ledger_account_id', $account->ledger_account_id)`, so reconciling the first bank OFFERED
 * THE SECOND BANK'S POSTINGS. An operator matches one, the statement balances, and the
 * reconciliation is wrong — which `BANK-RECONCILIATION-PLAN.md` names as worse than not
 * reconciling at all, because a wrong match marks money verified.
 *
 * The document now says which account it moved through, and `App\Support\MoneyAccount` posts there.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'TB']);
    $this->tenant = makeTenant(['name' => 'Two Banks Retail']);
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);

    // Two real bank accounts in ONE mall, each mapped to its own chart account — and NEITHER of
    // them the `bank` or `cash` ROLE account, or the fallback case below would be comparing an
    // account with itself and would pass with this whole change reverted. (It did, first time.)
    $resolver = app(AccountResolver::class);
    $roles = [$resolver->id('bank', $this->asset->id), $resolver->id('cash', $this->asset->id)];

    $chart = LedgerAccount::query()
        ->where('type', 'asset')
        ->where('is_postable', true)
        ->whereNotIn('id', $roles)
        ->take(2)
        ->get();

    expect($chart)->toHaveCount(2);

    $this->cib = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'CIB Current', 'bank_name' => 'CIB',
        'account_number' => '100200300', 'ledger_account_id' => $chart[0]->id, 'is_active' => true,
    ]);

    $this->nbe = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'NBE Collections', 'bank_name' => 'NBE',
        'account_number' => '900800700', 'ledger_account_id' => $chart[1]->id, 'is_active' => true,
    ]);

    // The premise: two DIFFERENT chart accounts, or nothing below distinguishes anything.
    expect($this->cib->ledger_account_id)->not->toBe($this->nbe->ledger_account_id);
});

/** The POSTED entry for one source document — found the way the sweep files it. */
function entryOf(Model $source): ?JournalEntry
{
    return JournalEntry::query()
        ->where('source_type', $source->getMorphClass())
        ->where('source_id', $source->getKey())
        ->where('status', 'posted')
        ->with('lines')
        ->first();
}

function receiptInto(BankAccount $account, float $amount, $lease): Payment
{
    return Payment::create([
        'asset_id' => $account->asset_id,
        'tenant_id' => $lease->tenant_id,
        'bank_account_id' => $account->id,
        'amount' => $amount,
        'method' => 'bank_transfer',
        'payment_date' => now()->toDateString(),
        'status' => 'captured',
    ]);
}

it('posts a receipt to the bank account it names, not the generic bank role', function () {
    $intoCib = receiptInto($this->cib, 10_000, $this->lease);
    $intoNbe = receiptInto($this->nbe, 25_000, $this->lease);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $debitOf = fn (Payment $p) => entryOf($p)->lines->firstWhere('debit', '>', 0)->ledger_account_id;

    expect($debitOf($intoCib))->toBe($this->cib->ledger_account_id)
        ->and($debitOf($intoNbe))->toBe($this->nbe->ledger_account_id);
});

it('offers the reconciler only its own bank\'s postings', function () {
    // The whole point. Before this, both receipts landed in the `bank` role account and reconciling
    // CIB offered NBE's money as a candidate.
    receiptInto($this->cib, 10_000, $this->lease);
    receiptInto($this->nbe, 25_000, $this->lease);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $statement = BankStatement::create([
        'bank_account_id' => $this->cib->id,
        'asset_id' => $this->asset->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'opening_balance' => 0,
        'closing_balance' => 10_000,
    ]);

    $line = $statement->lines()->create([
        'value_date' => now()->toDateString(),
        'amount' => 10_000,
        'description' => 'Transfer in',
        'row_hash' => 'h1',
    ]);

    $candidates = app(MatchBankStatementLineService::class)->candidatesFor($line);
    $accounts = $candidates->pluck('ledger_account_id')->unique();

    expect($candidates)->not->toBeEmpty()
        ->and($accounts->all())->toBe([$this->cib->ledger_account_id])
        ->and($accounts)->not->toContain($this->nbe->ledger_account_id);
});

it('falls back to the rail, then the role, when no bank account is named', function () {
    // Null is the normal state and must behave exactly as before — otherwise this change would move
    // every existing balance on the day it deployed.
    $plain = Payment::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'amount' => 5_000,
        'method' => 'bank_transfer',
        'payment_date' => now()->toDateString(),
        'status' => 'captured',
    ]);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $debit = entryOf($plain)->lines->firstWhere('debit', '>', 0);
    $bankRole = app(AccountResolver::class)->id('bank', $this->asset->id);

    expect($debit->ledger_account_id)->toBe($bankRole)
        // …and that really is a different account from either bank's, so the assertion above is
        // about the fallback and not about all three resolving to one thing.
        ->and($bankRole)->not->toBe($this->cib->ledger_account_id)
        ->and($bankRole)->not->toBe($this->nbe->ledger_account_id);
});

it('falls through to the rail when the bank account names an unpostable chart account', function () {
    // Re-checked at POSTING time rather than trusted from the form: an account can be retired long
    // after a bank account was pointed at it. The entry must still post and still balance — throwing
    // would kill the sync job and leave the document unposted with nothing on screen to say so.
    LedgerAccount::whereKey($this->cib->ledger_account_id)->update(['is_active' => false]);
    MoneyAccount::flush();

    $payment = receiptInto($this->cib, 7_000, $this->lease);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $entry = entryOf($payment);
    $bankRole = app(AccountResolver::class)->id('bank', $this->asset->id);

    expect($entry)->not->toBeNull()
        ->and($entry->lines->firstWhere('debit', '>', 0)->ledger_account_id)->toBe($bankRole);
});

it('carries the bank account through the SERVICE paths, not just the plain forms', function () {
    // Four of the six documents save straight from a Filament resource form, so a fillable column is
    // enough. Two do not: a supplier payment goes through `VendorBillService::recordPayment()` and an
    // owner disbursement through `DisbursementService::schedule()`, and both build their row from an
    // explicit list of fields. A field added to the form and not to the service is a control that
    // saves NOTHING — it renders, it validates, the operator picks an account and the document
    // records none. Caught in my own diff; pinned here.
    $vendor = Vendor::create(['name' => 'Banked Supplier', 'status' => 'active']);

    $bill = VendorBill::create([
        'number' => 'VB-'.uniqid(), 'vendor_id' => $vendor->id, 'asset_id' => $this->asset->id,
        'category' => 'cleaning_security', 'status' => 'approved',
        'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
    ]);

    app(VendorBillService::class)->recordPayment(
        $bill, 5000.0, 'bank_transfer', now(), null, $this->nbe->id,
    );

    $payment = $bill->fresh()->payments()->sole();

    expect($payment->bank_account_id)->toBe($this->nbe->id);

    // …and it reaches the LEDGER, which is the only reason the column exists.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(entryOf($payment)->lines->firstWhere('credit', '>', 0)->ledger_account_id)
        ->toBe($this->nbe->ledger_account_id);

    // The control: omitting it still works and still posts, to the rail's answer.
    $bill2 = VendorBill::create([
        'number' => 'VB-'.uniqid(), 'vendor_id' => $vendor->id, 'asset_id' => $this->asset->id,
        'category' => 'cleaning_security', 'status' => 'approved',
        'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000,
    ]);

    app(VendorBillService::class)->recordPayment($bill2, 1000.0);

    expect($bill2->fresh()->payments()->sole()->bank_account_id)->toBeNull();
});

it('refuses a document that names another mall\'s bank account', function () {
    // A `BankAccount` is `#[PropertyOwned]`, so this would post Mall A's money into Mall B's bank
    // chart account — an error that BALANCES, and that the reconciliation would then present as a
    // real candidate on the wrong statement.
    //
    // The picker is not the guard. `BankAccountField` narrows to the selected property, and
    // `EntitySelect`'s label lookup resolves through the VISIBLE ones — so an operator holding two
    // malls could submit the other's account and it would validate. And the value arrives as a
    // Livewire payload either way.
    // `payments` carries no `asset_id` of its own — a receipt's books dimension is derived from the
    // invoices it settles — so the comparison for those two documents is the mall the operator is
    // working in, which is also the list they picked from.
    Filament::setTenant($this->asset, isQuiet: true);

    $otherMall = makeAsset(['code' => 'XB']);

    $foreign = BankAccount::create([
        'asset_id' => $otherMall->id, 'name' => 'Other Mall Bank', 'bank_name' => 'HSBC',
        'account_number' => '111222333', 'is_active' => true,
    ]);

    expect(fn () => Payment::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'bank_account_id' => $foreign->id,
        'amount' => 1_000,
        'method' => 'bank_transfer',
        'payment_date' => now()->toDateString(),
        'status' => 'captured',
    ]))->toThrow(DomainException::class);

    // The control: this mall's own account saves, so the refusal is about the property and not
    // about the column being unusable.
    $ok = receiptInto($this->cib, 1_000, $this->lease);

    expect($ok->fresh()->bank_account_id)->toBe($this->cib->id);

    Filament::setTenant(null, isQuiet: true);
});

it('refuses it on a document that carries its own property too', function () {
    // The four that DO have an `asset_id` are compared against their own dimension rather than the
    // operator's context, so the rule holds on a console or import path where there is no context.
    $otherMall = makeAsset(['code' => 'XC']);

    $foreign = BankAccount::create([
        'asset_id' => $otherMall->id, 'name' => 'Elsewhere Bank', 'bank_name' => 'AAIB',
        'account_number' => '444555666', 'is_active' => true,
    ]);

    expect(fn () => Expense::create([
        'asset_id' => $this->asset->id,
        'bank_account_id' => $foreign->id,
        'category' => 'maintenance',
        'amount' => 500, 'vat_amount' => 0,
        'paid_from' => 'bank',
        'expense_date' => now()->toDateString(),
        'status' => 'recorded',
    ]))->toThrow(DomainException::class);

    // The control: this mall's own account is accepted on the same document.
    $ok = Expense::create([
        'asset_id' => $this->asset->id,
        'bank_account_id' => $this->nbe->id,
        'category' => 'maintenance',
        'amount' => 500, 'vat_amount' => 0,
        'paid_from' => 'bank',
        'expense_date' => now()->toDateString(),
        'status' => 'recorded',
    ]);

    expect($ok->fresh()->bank_account_id)->toBe($this->nbe->id);
});

it('refuses the same on every one of the six documents', function () {
    // A guard on one model is a guard on one model. This is a concern, and the sweep is what says
    // so — the six were converted by a script, and a script can miss one silently.
    $models = collect(File::allFiles(app_path('Models')))
        ->filter(fn ($f) => str_contains($f->getContents(), 'use RecordsBankAccount;'))
        ->map(fn ($f) => 'App\\Models\\'.$f->getFilenameWithoutExtension())
        ->values();

    expect($models)->toHaveCount(6);

    foreach ($models as $model) {
        expect(in_array('bank_account_id', (new $model)->getFillable(), true))
            ->toBeTrue("{$model} carries the concern and cannot be assigned a bank account.");

        expect(method_exists($model, 'bankAccount'))->toBeTrue();
    }
});
