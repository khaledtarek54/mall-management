<?php

use App\Models\BankAccount;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\Payroll;
use App\Services\Accounting\AccountResolver;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * **The demo has to SHOW the two-bank case, or EG-12 reads as unbuilt.** — demo data is part of the
 * feature.
 *
 * Nothing seeded a `BankAccount` at all when the field shipped, so every picker on the six money
 * forms was empty and the whole thing looked like a screen someone forgot to wire. Fixing that
 * introduced two failures of its own, both silent, and both re-introducible by moving one line —
 * which is what this pins:
 *
 *  1. **The register was seeded LATE.** `generateInvoiceHistory()` and `seedCurrentMonthPayments()`
 *     both raise receipts and both fire earlier in `run()`, so `demoBankAccountFor()` answered null
 *     for almost all of them: measured, 1 of 194 payments named a bank. Every screen still
 *     rendered, every test stayed green, and the demo simply showed "the rail decides" everywhere.
 *  2. **The chart accounts were the POSTING ROLES.** "The first two postable asset accounts by
 *     code" are `11101001 Main Cashier` and `11102001 Bank Account` — so CIB's receipts would have
 *     landed in the till, and NBE would have resolved to exactly the account
 *     `App\Support\MoneyAccount`'s floor already picks. Two banks, and nothing to tell apart.
 *
 * Both are ABSENCE failures — nothing errors, nothing is red, the numbers are simply uninteresting
 * — which is the class of thing this codebase has been bitten by repeatedly and the reason this
 * asserts on the seeded result rather than on the seeder's shape.
 */
/**
 * ONE case, deliberately. `DemoSeeder` takes ~30s and Pest parallelises per FILE, so four cases
 * that each re-seed it is eight minutes on one worker while nine idle — the exact shape that used
 * to set the floor under the whole suite. Everything below is one story about one seeded database.
 */
it('demonstrates two banks in one mall on a fresh demo', function () {
    // `DemoSeeder` does not lay the chart down — `DatabaseSeeder` runs the accounting seeders ahead
    // of it, and `seedBankAccounts()` bails when `11102 Banks` is missing rather than guessing a
    // parent. Seeding only the demo would leave zero bank accounts and this test asserting on an
    // absence it caused itself.
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(AccountingSeeder::class);
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(DemoSeeder::class);

    $accounts = BankAccount::with('ledgerAccount')->get();

    expect($accounts)->toHaveCount(2);

    $assetId = $accounts->first()->asset_id;
    $resolver = app(AccountResolver::class);
    $bankRole = $resolver->id('bank', $assetId);
    $roles = [$bankRole, $resolver->id('cash', $assetId)];

    // ── Its own chart leaf, and never a posting role ─────────────────────────────────────────────
    foreach ($accounts as $account) {
        expect($account->ledger_account_id)->not->toBeNull("{$account->name} maps to no chart account.");

        expect(in_array($account->ledger_account_id, $roles, true))->toBeFalse(
            "{$account->name} is mapped to a POSTING ROLE account, so its money is indistinguishable "
            .'from money that names no bank at all.'
        );

        expect($account->ledgerAccount->is_postable)->toBeTrue();
    }

    // …and not to each other's, or the reconciliation matcher offers one bank's postings against
    // the other's statement — the defect EG-12 exists to fix.
    expect($accounts[0]->ledger_account_id)->not->toBe($accounts[1]->ledger_account_id);

    // ── Real money through both, with the floor still visible ────────────────────────────────────
    foreach ($accounts as $account) {
        $lines = JournalLine::where('ledger_account_id', $account->ledger_account_id)->count();

        // A generous floor on purpose: the exact figure moves whenever a lease, a rail or a payment
        // site changes, and pinning it would make an unrelated seeder edit look like a regression.
        // What must never come back is the "1 of 194" shape — a register seeded after the money.
        expect($lines)->toBeGreaterThan(10, "{$account->name} carries almost no posted money.");
    }

    // The generic `bank` role still holds the documents that name no account — the floor working,
    // not a gap. At zero, the seeder would have stopped showing that naming one is OPTIONAL.
    expect(JournalLine::where('ledger_account_id', $bankRole)->count())->toBeGreaterThan(0);

    // ── Every register with demo rows says something ─────────────────────────────────────────────
    // Receipts and expenses were the two the first cut covered; payroll and deposit movements are
    // the other two with demo rows, and they were empty, so four of the six new columns said
    // nothing. (Vendor-bill payments and disbursements have no demo rows at all — a pre-existing
    // gap in the seeder, not this feature's.)
    expect(Payment::whereNotNull('bank_account_id')->count())->toBeGreaterThan(50);
    expect(Expense::whereNotNull('bank_account_id')->count())->toBeGreaterThan(0);
    expect(Payroll::whereNotNull('bank_account_id')->count())->toBeGreaterThan(0);
    expect(DepositTransaction::whereNotNull('bank_account_id')->count())->toBeGreaterThan(0);

    // Both banks are used, or the demo shows a two-bank feature with one bank.
    expect(Payment::whereNotNull('bank_account_id')->distinct()->count('bank_account_id'))->toBe(2);

    // A card capture and a cheque are NOT in the bank on their own date — that timing gap is the
    // known-wrong thing `PaymentMethod` documents. Naming an account on them would offer them as
    // reconciliation candidates days before the money arrived.
    expect(Payment::whereIn('method', ['card', 'cheque'])->whereNotNull('bank_account_id')->count())
        ->toBe(0, 'A deferred-settlement rail was given a bank account, which makes the matcher lie.');

    // ── Beside the generic account, never instead of it ──────────────────────────────────────────
    // `11102001` must stay postable and stay the `bank` role: it is the floor for every document
    // that names no account, and re-pointing the role would change what an unconfigured install
    // posts.
    $generic = LedgerAccount::where('code', '11102001')->first();

    expect($generic)->not->toBeNull()
        ->and($generic->is_postable)->toBeTrue()
        ->and($generic->is_active)->toBeTrue()
        ->and($bankRole)->toBe($generic->id);
});
