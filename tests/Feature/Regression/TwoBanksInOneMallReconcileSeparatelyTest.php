<?php

use App\Filament\Admin\Resources\Payments\Pages\ListPayments;
use App\Filament\Exports\PaymentExporter;
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
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Services\Banking\MatchBankStatementLineService;
use App\Services\VendorBillService;
use App\Support\Filament\BankAccountFilter;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

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
    //
    // **Excluding EVERY posting role, not just cash and bank (2026-09-02).** This used to take the
    // first two postable asset accounts that were not those two, which on the seeded chart is
    // `11201001 Accounts Receivable` — mapped to the `accounts_receivable` role. That became
    // impossible when `BankAccount::assertLedgerAccountIsItsOwn()` started refusing a bank pointed
    // at ANY role, and the fixture's premise was simply narrower than the real rule: a bank sharing
    // the AR control account is a worse version of the mistake this file is about, not an exempt
    // one. Minted instead, through the method the ledger-account picker's own create button calls,
    // so the fixture asks for what the running system would actually produce.
    $chart = collect([
        app(MintBankLedgerAccountService::class)->mint('CIB — test leaf', $this->asset->id),
        app(MintBankLedgerAccountService::class)->mint('NBE — test leaf', $this->asset->id),
    ])->filter()->values();

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

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

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
    // No `asset_id` — `payments` has no such column. A receipt's books dimension comes from the
    // invoices it settles, which is why the cross-property guard falls back to the operator's
    // context for this one.
    return Payment::create([
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

it('refuses the same on every one of the seven documents', function () {
    // A guard on one model is a guard on one model. This is a concern, and the sweep is what says
    // so — the six were converted by a script, and a script can miss one silently.
    //
    // SEVEN since 2026-09-02. `RecurringExpense` grew a `bank_account_id` the day before with no
    // relation and no guard, so a schedule could name ANOTHER MALL's account and stamp it onto
    // every cost it generated — the cross-property posting this sweep exists to refuse, arrived at
    // from one document upstream. Counting rather than listing is what made that visible.
    //
    // The first cut of this case asserted only that each model was FILLABLE with the column and had
    // a `bankAccount()` method, and called itself a refusal sweep. That is the weaker-property trap
    // CLAUDE.md names three times: it would stay green with `bootRecordsBankAccount()` emptied out,
    // because the trait would still be `use`d and the relation would still exist. So each of the
    // six is DRIVEN, and each refusal is paired with a control on its own mall's account.
    Filament::setTenant($this->asset, isQuiet: true);

    $otherMall = makeAsset(['code' => 'XS']);

    $foreign = BankAccount::create([
        'asset_id' => $otherMall->id, 'name' => 'Sweep Bank', 'bank_name' => 'QNB',
        'account_number' => '777888999', 'is_active' => true,
    ]);

    $models = collect(File::allFiles(app_path('Models')))
        ->filter(fn ($f) => str_contains($f->getContents(), 'use RecordsBankAccount;'))
        ->map(fn ($f) => 'App\\Models\\'.$f->getFilenameWithoutExtension())
        ->values();

    expect($models)->toHaveCount(7);

    // Did naming this account raise the REFUSAL? A save that dies further down on a NOT NULL column
    // is not a refusal — the guard runs on `creating`, before the insert, so a document with nothing
    // else filled in reaches it and no per-model fixture is needed. Anything other than a
    // `DomainException` is therefore "the guard let this through".
    //
    // `creating` and not `saving`, and the difference is the whole reason the guard works on the
    // documents that do not carry a typed `asset_id`: a trait's boot method runs before the class's
    // own `booted()`, so a `saving` listener registered in a concern fires BEFORE the model derives
    // its own property — and `DepositTransaction` derives it from its lease in exactly such a hook.
    // On `saving` this guard saw a null property there and skipped, which is how a deposit receipt
    // naming another mall's account was being accepted.
    $refuses = function (string $model, int $bankAccountId): bool {
        $document = new $model;

        if (Schema::hasColumn($document->getTable(), 'asset_id')) {
            $document->asset_id = $this->asset->id;
        }

        $document->bank_account_id = $bankAccountId;

        try {
            $document->save();
        } catch (DomainException) {
            return true;
        } catch (Throwable) {
            return false;
        }

        return false;
    };

    foreach ($models as $model) {
        expect(in_array('bank_account_id', (new $model)->getFillable(), true))
            ->toBeTrue("{$model} carries the concern and cannot be assigned a bank account.");

        expect(method_exists($model, 'bankAccount'))->toBeTrue();

        expect($refuses($model, $foreign->id))
            ->toBeTrue("{$model} accepted another mall's bank account.");

        // The control. Without it a guard that refused EVERY bank account would read as a pass.
        expect($refuses($model, $this->cib->id))
            ->toBeFalse("{$model} refused its own mall's bank account.");
    }

});

it('keeps a RETIRED bank account posting where its money actually went', function () {
    // `bank_account_id` is classified DERIVED, so `LedgerPoster::sync()` void-and-reposts an entry
    // whose account no longer matches. With the lookup excluding trashed rows, soft-deleting a bank
    // account therefore rewrote its own history to the generic `bank` account on the next sweep —
    // silently, with no operator action, undoing the separation the reconciliation is built on.
    $payment = receiptInto($this->cib, 12_000, $this->lease);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(entryOf($payment)->lines->firstWhere('debit', '>', 0)->ledger_account_id)
        ->toBe($this->cib->ledger_account_id);

    $this->cib->delete();

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // Money that moved through an account moved through it, whatever the register says today.
    expect(entryOf($payment)->lines->firstWhere('debit', '>', 0)->ledger_account_id)
        ->toBe($this->cib->ledger_account_id);
});

it('sees a bank account registered by another process, with no flush', function () {
    // The memo this class used to keep was process-local, and the real posting path is a QUEUED job
    // in a long-lived worker — so a bank account created after the worker booted was invisible to
    // it, and the posting fell to the generic role. Inserted with the query builder so no model
    // event fires: that is what a second process looks like from in here.
    $resolver = app(AccountResolver::class);
    $roles = [$resolver->id('bank', $this->asset->id), $resolver->id('cash', $this->asset->id)];

    $chart = LedgerAccount::query()->where('type', 'asset')->where('is_postable', true)
        ->whereNotIn('id', [$this->cib->ledger_account_id, $this->nbe->ledger_account_id])
        ->whereNotIn('id', $roles)
        ->firstOrFail();

    // The same premise `beforeEach` insists on, and for the same reason: if the target were the
    // role account, the assertion below would be comparing the fallback with itself and would pass
    // with this whole change reverted.
    expect($chart->id)->not->toBeIn($roles);

    // Warm the memo FOR REAL. The first version of this said "warm whatever caches exist by asking
    // first" and then called `receiptInto()`, which asks nothing: `ACCOUNTING_REALTIME_LEDGER_SYNC`
    // is false under test (phpunit.xml), so a `Payment::create()` dispatches no
    // `SyncDocumentToLedger` and no journalizer runs — `MoneyAccount::for()` is reached from the
    // thirteen journalizers and nowhere else. So the only lookup in the case happened AFTER the
    // insert, a restored memo would have been built from a database that already held the row, and
    // the case was green either way: it could not fail on the one property its own title names.
    // Running the sweep here is what makes the memo warm, and therefore stale.
    receiptInto($this->cib, 1_000, $this->lease);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $id = DB::table('bank_accounts')->insertGetId([
        'asset_id' => $this->asset->id, 'name' => 'Late Arrival Bank', 'bank_name' => 'QNB',
        'account_number' => '777', 'ledger_account_id' => $chart->id, 'is_active' => true,
        'currency' => 'EGP', 'search_text' => 'late arrival bank',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'bank_account_id' => $id, 'amount' => 3_000, 'method' => 'bank_transfer',
        'payment_date' => now()->toDateString(), 'status' => 'captured',
    ]);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(entryOf($payment)->lines->firstWhere('debit', '>', 0)->ledger_account_id)->toBe($chart->id);
});

it('refuses a document MOVED to another mall while keeping this mall\'s bank account', function () {
    // The other direction. Guarding only `bank_account_id` let the same wrong posting be reached by
    // re-homing the document instead of re-pointing the account.
    $otherMall = makeAsset(['code' => 'XM']);

    $expense = Expense::create([
        'asset_id' => $this->asset->id,
        'bank_account_id' => $this->cib->id,
        'category' => 'maintenance',
        'amount' => 900, 'vat_amount' => 0,
        'paid_from' => 'bank',
        'expense_date' => now()->toDateString(),
        'status' => 'recorded',
    ]);

    expect(fn () => $expense->update(['asset_id' => $otherMall->id]))
        ->toThrow(DomainException::class);

    // The control: moving a document that names NO bank account is still allowed — re-homing is a
    // legitimate correction and this guard is only about the pair being consistent.
    $plain = Expense::create([
        'asset_id' => $this->asset->id,
        'category' => 'maintenance',
        'amount' => 400, 'vat_amount' => 0,
        'paid_from' => 'cash',
        'expense_date' => now()->toDateString(),
        'status' => 'recorded',
    ]);

    $plain->update(['asset_id' => $otherMall->id]);

    expect($plain->fresh()->asset_id)->toBe($otherMall->id);
});

it('lets an operator SEE which bank a document went through, and filter by it', function () {
    // The field shipped write-only: six forms, no column, no infolist entry, no filter. An operator
    // could set the account and never see it again — so *"which documents went through CIB?"*, the
    // one question this feature exists to answer, was unanswerable from any register.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    // Allocated to a real invoice, because `payments` has no `asset_id`: `PaymentResource` scopes
    // through the invoices a receipt settles, so an unallocated one is on nobody's list.
    $intoCib = receiptInto($this->cib, 4_000, $this->lease);
    $intoNbe = receiptInto($this->nbe, 6_000, $this->lease);

    foreach ([[$intoCib, 4_000], [$intoNbe, 6_000]] as [$payment, $amount]) {
        $invoice = makeInvoice($this->lease, ['total' => $amount, 'balance' => $amount]);
        $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $amount]);
    }

    asTenant($this->asset, function () use ($intoCib, $intoNbe) {
        $page = Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$intoCib, $intoNbe]);

        // The column reads the bank's NAME, not its id — and it is toggled hidden by default, so
        // this turns it on the way the column manager does. Rendering it is the point, not its
        // presence in a schema: a `->description()` closure reaching through a null relation is
        // exactly the shape that 500s a whole list in production.
        $state = collect($page->instance()->getDefaultTableColumnState())
            ->map(fn (array $item) => $item['name'] === 'bankAccount.name'
                ? [...$item, 'isToggled' => true]
                : $item)
            ->all();

        expect(collect($state)->firstWhere('name', 'bankAccount.name'))->not->toBeNull();

        $page->call('applyTableColumnManager', $state)
            ->assertSee('CIB Current')
            ->assertSee('NBE Collections')
            // …and the filter answers the same question faster. Applied on THIS component, not a
            // fresh one: `TableDefaults` turns on `persistFiltersInSession()` app-wide, so a second
            // `Livewire::test()` here would silently start out already filtered.
            ->filterTable('bank_account_id', $this->cib->id)
            ->assertCanSeeTableRecords([$intoCib])
            ->assertCanNotSeeTableRecords([$intoNbe]);
    });
});

it('offers the two accounts in the filter without making the operator type', function () {
    // `EntitySelectFilter` exists so a filter reads exactly like the form picker beside it. The
    // field browses (it passes `->suggest()`), and without `->preload()` the filter fell to
    // `applyTo()`'s static empty option list — which Filament renders as "start typing to search".
    // On a mall holding exactly two accounts that is indistinguishable from "there are no bank
    // accounts", the empty-dropdown failure `EntitySelect` was written to eliminate.
    Filament::setTenant($this->asset, isQuiet: true);

    $select = BankAccountFilter::make()->getFormField();

    expect($select->isPreloaded())->toBeTrue();

    $options = $select->getOptions();

    expect($options)->toHaveCount(2)
        ->and(array_keys($options))->toEqualCanonicalizing([$this->cib->id, $this->nbe->id]);
});

it('carries the bank account into the payment EXPORT, not just onto the screen', function () {
    // The list gained a column and a filter; the CSV is where a reconciliation is actually done.
    // Filament's export path applies the operator's filters but renders `ExportColumn`s, so a table
    // column reaches none of it — narrow to CIB, export, and the file could not be told from NBE's.
    $names = collect(PaymentExporter::getColumns())
        ->map(fn ($column) => $column->getName());

    expect($names)->toContain('bankAccount.name');
});
