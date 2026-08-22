<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\PaymentMethod;
use App\Services\Accounting\AccountResolver;

/**
 * Which chart account did this money move through? — the ONE answer, for all thirteen journalizers.
 *
 * Three questions in descending order of specificity, and each is a real one an operator can answer:
 *
 *   1. **The document's own bank account.** `bank_accounts.ledger_account_id` — the account the cash
 *      actually left or entered. Most specific, and the only one that can tell two banks apart.
 *   2. **The rail's account.** `payment_methods.ledger_account_id` — where money on this rail lands.
 *      A clearing account per rail is how an operator separates "captured on the card" from "settled
 *      in the bank" (see {@see PaymentMethod}).
 *   3. **The posting role.** `cash` for cash, `bank` for everything else — verbatim what every
 *      journalizer hard-coded before the rail catalogue, so an unconfigured install is unchanged.
 *
 * ## Why this exists
 *
 * `bank_accounts` shipped with a `ledger_account_id` and **no journalizer read it**. Every posting
 * resolved the `bank` role, one account per property — so a mall banking in two places put both
 * banks' money in the same chart account, and
 * `MatchBankStatementLineService::candidatesFor()`, which finds candidates by that very account,
 * offered the OTHER bank's postings when reconciling the first. An operator matches one, the
 * statement balances, and the reconciliation is wrong. The plan calls that worse than not
 * reconciling at all, and it is right: a wrong match marks money verified.
 *
 * ## Falling THROUGH rather than throwing
 *
 * A bank account that has gone, or was never mapped to the chart, falls to the next question. The
 * entry still posts and still balances; throwing here would kill the sync job and leave the document
 * unposted with nothing on screen to say so — the same reasoning as
 * {@see PaymentMethod::accountIdOrFloor()}, which this delegates to for questions 2 and 3.
 */
final class MoneyAccount
{
    public static function for(
        ?int $bankAccountId,
        ?string $rail,
        ?int $assetId,
        AccountResolver $accounts,
    ): int {
        $fromBank = self::ledgerAccountOf($bankAccountId);

        if ($fromBank !== null) {
            return $fromBank;
        }

        return PaymentMethod::accountIdOrFloor($rail, $assetId, $accounts);
    }

    /**
     * The chart account a bank account IS, if it names one that still exists and can be posted to.
     *
     * ## Read every time, deliberately — there is no memo
     *
     * The first cut memoised the whole map in `app()->instance()`, which is PROCESS-LOCAL, and the
     * near-real-time posting path is `SyncDocumentToLedger` — a queued job in a long-lived
     * `queue:work` daemon. Laravel resets only SCOPED instances between jobs, so the map outlived
     * every write that should have invalidated it: a bank account registered after the worker
     * booted posted to the generic `bank` role (re-creating the exact bug this class exists to fix),
     * and one whose chart account was re-pointed kept posting to the old one. Both proven.
     *
     * The register holds a handful of rows and this is asked once per posted document, so the memo
     * was buying almost nothing and paying for it in staleness. A correct answer every time beats a
     * cached wrong one.
     *
     * ## Trashed accounts still answer
     *
     * `withTrashed()`, and that is not a detail. `bank_account_id` is classified DERIVED, so
     * `LedgerPoster::sync()` void-and-reposts an entry whose account no longer matches — meaning a
     * soft-deleted bank account would silently rewrite its own posting history to the generic
     * account on the next sweep, undoing the separation the bank reconciliation is built on. Money
     * that moved through an account it moved through, whatever the register says today.
     *
     * ## Re-checked at POSTING time, in the SAME query
     *
     * A chart account can be retired or made a summary parent long after a bank account was pointed
     * at it, so postability is re-asked on every posting — `AccountResolver` does the same for every
     * role-based lookup. It is a JOIN rather than a second `find()` because this runs once per
     * document in `accounting:sync-ledger --all`, and that path is already the one CLAUDE.md flags
     * for per-query overhead (18k queries for 274ms of actual SQL). One query answers both halves.
     *
     * **`deleted_at` is spelled out because a raw join has no global scopes.** `LedgerAccount` uses
     * `SoftDeletes`, so the `find()` this replaced returned null for a deleted chart account and
     * fell through to the rail; the join would happily have posted into it. Two soft-delete
     * decisions in one query, and they point OPPOSITE ways on purpose: a trashed BANK account still
     * answers (money that moved through it moved through it), a trashed CHART account does not
     * (nothing may post into a deleted account, whatever points at it).
     */
    private static function ledgerAccountOf(?int $bankAccountId): ?int
    {
        if ($bankAccountId === null) {
            return null;
        }

        try {
            $id = BankAccount::withTrashed()
                ->whereKey($bankAccountId)
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'bank_accounts.ledger_account_id')
                ->whereNull('ledger_accounts.deleted_at')
                ->where('ledger_accounts.is_postable', true)
                ->where('ledger_accounts.is_active', true)
                ->value('ledger_accounts.id');
        } catch (\Throwable) {
            // Before either table exists.
            return null;
        }

        return $id === null ? null : (int) $id;
    }
}
