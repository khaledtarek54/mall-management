<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bank account did this money actually move through?
 *
 * `bank_accounts` has existed since 2026-08-11, with a `ledger_account_id` naming the chart account
 * it IS — and **no journalizer read it**. Every posting resolved the `bank` ROLE, one account per
 * property, so a mall banking in two places posted both banks' money to the same chart account.
 *
 * That is not a cosmetic gap: `MatchBankStatementLineService::candidatesFor()` finds candidates by
 * `where('ledger_account_id', $account->ledger_account_id)` — journal lines on the bank account's own
 * chart account. With both accounts resolving to one, reconciling the first bank OFFERS THE SECOND
 * BANK'S POSTINGS as candidates. An operator matches one, it balances, and it is wrong — which the
 * reconciliation plan names as worse than not reconciling at all.
 *
 * ## Six tables, and why not thirteen
 *
 * These are the documents a bank statement line can explain: an AR receipt, a supplier payment, an
 * expense, a deposit movement, an owner disbursement, a payroll run. The other seven money sources
 * that resolve a cash-or-bank account — custody, employee advances and their repayments, marketing
 * spend, fixed-asset funding and disposal proceeds — are petty-cash and internal flows. They keep
 * resolving through the RAIL, which is correct and unchanged, and `App\Support\MoneyAccount` is the
 * one seam all thirteen now call, so giving one of them a bank account later is a column and a form
 * field rather than a change to how posting works.
 *
 * ## Nullable, and null is the normal state
 *
 * An operator says which account when they know; until they do, the rail answers exactly as before
 * and no balance moves. `nullOnDelete` rather than `restrictOnDelete`: a bank account is
 * `#[DeletionAllowed]` configuration, and losing the annotation must never take a posted money
 * document with it.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const TABLES = [
        'payments',
        'vendor_bill_payments',
        'expenses',
        'deposit_transactions',
        'disbursements',
        'payrolls',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreignId('bank_account_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('bank_accounts')
                    ->nullOnDelete();

                // The matcher reads by account; the register reads by document. Both want this.
                // Not a duplicate of the FK's own index: InnoDB auto-creates one only while the
                // column has none, and drops it again when an explicit index takes over the job.
                // Verified on the MySQL we run — one index per column, named below. SQLite creates
                // no FK index at all, so it needs this outright.
                $blueprint->index('bank_account_id', substr($table, 0, 20).'_bank_account_index');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex(substr($table, 0, 20).'_bank_account_index');
                $blueprint->dropConstrainedForeignId('bank_account_id');
            });
        }
    }
};
