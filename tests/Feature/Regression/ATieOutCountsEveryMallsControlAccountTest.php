<?php

/**
 * A control-account tie-out counts EVERY account the role points at, not just the global default.
 *
 * The posting map offers a per-mall override on a control of its own (`PropertyField::scope()`),
 * and every journalizer passes the document's `asset_id` into `AccountResolver::account()` — so
 * one `('accounts_receivable', mall XX)` row really does send that mall's invoices to a different
 * chart account. `BooksReconciliationService::glTieOut()` and `DepositHoldings::glBalance()` both
 * read the GLOBAL mapping alone, and both compare it against what EVERY mall's source documents
 * imply. The delta is that mall's whole receivable (or its whole deposit liability), it never
 * clears, `books_tie_out` is red for ever, and `atriom:preflight` blocks the next deploy for a
 * reason that has nothing to do with the deploy.
 *
 * Measured on the dev and QA databases (2026-09-04): 52 mappings, 0 of them property-scoped — so
 * this is a supported configuration nobody has used yet rather than a live fault. `accountsFor()`
 * is the one seam both readers now take.
 */

use App\Models\AccountMapping;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\BillSecurityDepositService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\DepositHoldings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

it('ties out when one mall keeps its receivables on a control account of its own', function () {
    $here = makeAsset(['code' => 'AW']);
    $elsewhere = makeAsset(['code' => 'XX']);

    $ownAr = LedgerAccount::create([
        'code' => '11201002',
        'name_en' => 'Tenant Receivables - Mall XX',
        'name_ar' => 'ذمم المستأجرين — مول XX',
        'type' => 'asset',
        'is_postable' => true,
        'is_active' => true,
    ]);

    AccountMapping::create([
        'key' => 'accounts_receivable',
        'asset_id' => $elsewhere->id,
        'ledger_account_id' => $ownAr->id,
    ]);

    $mine = makeInvoice(makeLease(makeUnit($here)));
    $theirs = makeInvoice(makeLease(makeUnit($elsewhere)));

    app(LedgerPoster::class)->sync($mine->fresh());
    app(LedgerPoster::class)->sync($theirs->fresh());

    // The premise. Without this the case would pass on a database where the override changed
    // nothing at all, and would prove nothing about the tie-out.
    expect((float) JournalLine::query()->where('ledger_account_id', $ownAr->id)->sum('debit'))
        ->toBe(11400.0)
        ->and(count(app(AccountResolver::class)->accountsFor('accounts_receivable')))->toBe(2);

    $tie = app(BooksReconciliationService::class)->glTieOut();

    expect($tie['configured'])->toBeTrue()
        ->and($tie['ar']['expected'])->toBe(22800.0)
        ->and($tie['ar']['gl'])->toBe(22800.0)
        ->and($tie['ar']['delta'])->toBe(0.0);
});

it('ties out when one mall keeps its deposit liability on a control account of its own', function () {
    $elsewhere = makeAsset(['code' => 'XX']);

    $ownDeposits = LedgerAccount::create([
        'code' => '21201002',
        'name_en' => 'Tenant Deposits Held - Mall XX',
        'name_ar' => 'تأمينات المستأجرين — مول XX',
        'type' => 'liability',
        'is_postable' => true,
        'is_active' => true,
    ]);

    AccountMapping::create([
        'key' => 'deposits_held',
        'asset_id' => $elsewhere->id,
        'ledger_account_id' => $ownDeposits->id,
    ]);

    $lease = makeLease(makeUnit($elsewhere), null, ['security_deposit' => 30000]);
    settleInvoiceInFull(app(BillSecurityDepositService::class)->bill($lease));

    // The REAL sweep, not LedgerPoster::post() by hand — per the GL invariant.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect((float) JournalLine::query()->where('ledger_account_id', $ownDeposits->id)->sum('credit'))
        ->toBe(30000.0);

    expect(DepositHoldings::glBalance())->toBe(30000.0)
        ->and(DepositHoldings::expectedGlBalance())->toBe(30000.0)
        ->and(app(BooksReconciliationService::class)->depositTieOutDiscrepancies())->toBe([]);
});

it('still says "not comparable" when the role is unmapped, rather than reporting a false gap', function () {
    // The control on the other side. A fresh install has no posting map at all, and "nothing to
    // compare against" must not render as "the books are broken" — otherwise every new deployment
    // fails its first reconcile for a reason that is not a problem.
    AccountMapping::query()->delete();

    expect(app(AccountResolver::class)->accountsFor('deposits_held'))->toBe([])
        ->and(DepositHoldings::glBalance())->toBeNull()
        ->and(app(BooksReconciliationService::class)->depositTieOutDiscrepancies())->toBe([]);
});
