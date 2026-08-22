<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\LedgerAccount;
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
    /** Memo: a payment run asks once per document, and this is a table of a handful of rows. */
    private const MEMO = 'money_account.bank_ledger';

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
     * Re-checked at POSTING time rather than trusted from the form: an account can be retired or
     * turned into a summary parent long after a bank account was pointed at it, and
     * `AccountResolver` performs the same check for every role-based lookup.
     */
    private static function ledgerAccountOf(?int $bankAccountId): ?int
    {
        if ($bankAccountId === null) {
            return null;
        }

        $map = app()->has(self::MEMO)
            ? app(self::MEMO)
            : tap(self::safeMap(), fn (array $m) => app()->instance(self::MEMO, $m));

        $ledgerId = $map[$bankAccountId] ?? null;

        if ($ledgerId === null) {
            return null;
        }

        $account = LedgerAccount::find($ledgerId);

        return ($account !== null && $account->is_postable && $account->is_active) ? $account->id : null;
    }

    /** @return array<int, int|null> */
    private static function safeMap(): array
    {
        try {
            return BankAccount::query()->pluck('ledger_account_id', 'id')->all();
        } catch (\Throwable) {
            // Before the table exists.
            return [];
        }
    }

    /** Drop the memo — the register's model calls this on write. */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }
}
