<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark a security-deposit receipt as a migrated OPENING ITEM — money the operator was already
 * holding on the day Atriom took over, carried in from the previous system.
 *
 * **The hole this closes.** The opening-balance machinery covered the trial balance (pasted by the
 * accountant), open receivables (`invoices.is_opening_balance`) and fixed assets
 * (`fixed_assets.opening_balance`) — and missed the one remaining sub-ledger: the per-lease deposit
 * register. `Lease::depositHeld()` sums this table, and `MoveOutStatementService` nets a tenant's
 * arrears off that figure, so at cutover an operator had exactly two options and both were wrong:
 *
 *   - key nothing, and every legacy tenant's held deposit reads **zero** — a move-out then refunds
 *     a deposit the books say was never taken, or nets nothing against arrears; or
 *   - key the historic receipts as ordinary movements, and each posts `Dr Cash / Cr Deposits Held`
 *     — **inventing a cash receipt that never happened on this system's watch** and double-counting
 *     a liability already carried in the opening trial balance.
 *
 * The failure was at least loud: `billing:reconcile`'s `deposits_tie_out` compares the register
 * against the `deposits_held` GL balance all-time, so the second option turns it red. What did not
 * exist was any way to make it green.
 *
 * So an opening deposit is a sub-ledger-only document, on exactly the terms an opening invoice
 * already is: `DepositTransactionJournalizer` returns no payload for it, and the GL side arrives
 * once, in the accountant's own hand, inside the opening entry. The tie-out then becomes the
 * migration's proof — green after cutover is the statement "the deposits I loaded equal the deposit
 * liability my accountant says I hold", and a migration that quietly loaded 90% of them is
 * otherwise indistinguishable from one that worked.
 *
 * Only a RECEIPT can meaningfully be an opening item — a refund or forfeit of a deposit taken
 * before cutover is a real movement of this system's cash and must post. The model refuses the
 * combination rather than silently ignoring the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_transactions', function (Blueprint $table) {
            $table->boolean('is_opening_balance')
                ->default(false)
                ->after('status')
                ->comment('Deposit already held at cutover: sub-ledger only, no GL posting');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_transactions', function (Blueprint $table) {
            $table->dropColumn('is_opening_balance');
        });
    }
};
