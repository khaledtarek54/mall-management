<?php

namespace App\Support;

use App\Models\JournalEntry;
use App\Models\JournalLine;

/**
 * **What is already posted on the account a posting role points at** (SW-134).
 *
 * Accounts are resolved at PAYLOAD time and never frozen onto the entry: every journalizer asks
 * `AccountResolver::account()`, which reads `account_mappings` live, and `LedgerPoster::matches()`
 * includes `ledger_account_id` in its line signature. So re-pointing a mapping means the next
 * `accounting:sync-ledger` sweep finds every historical document's entry no longer matching, voids
 * it, and re-posts it against the new account — up to a week later, with nobody having confirmed it.
 *
 * Nothing gated that. `AccountMapping::booted()` guards duplicates and the deletion of a global
 * default; `SealedPeriod::guard()` returns immediately because `AccountMapping` is not a GL SOURCE;
 * and `ChangeImpact::POLICY` classifies the columns of sources, not of a configuration table.
 *
 * **The split is the whole point.** A document in an OPEN period is re-derived — the totals move but
 * the books stay coherent. A document in a CLOSED period CANNOT be: the re-post is refused, so the
 * entry keeps the old account while the mapping says otherwise, and `billing:reconcile --deep`
 * reports drift for ever, which is what turns `atriom:preflight` permanently red and blocks the next
 * deploy for a reason unrelated to the deploy.
 *
 * This only tells the operator what they are about to do. **Whether a mapping change should be
 * PROSPECTIVE at all is an accounting decision nobody has taken** — Yardi's answer is that it is —
 * and until it is taken, the honest thing is to show the number and let a person decide. See
 * SW-134's other two pieces in the sweep document.
 */
class PostingRoleExposure
{
    /**
     * Posted journal lines currently sitting on `$accountId`, split by whether their period is open.
     *
     * @return array{total: int, open: int, closed: int}
     */
    public static function on(?int $accountId): array
    {
        if ($accountId === null) {
            return ['total' => 0, 'open' => 0, 'closed' => 0];
        }

        // `REPORTABLE_STATUSES`, not `posted` alone: a voided entry and the entry that replaced it
        // are both real history, and counting only `posted` reports a reversal without the movement
        // it reversed. The same convention the ledger reports use — summing one without the other is
        // how a −14,000 figure got quoted on this project once already.
        $lines = JournalLine::query()
            ->where('ledger_account_id', $accountId)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', JournalEntry::REPORTABLE_STATUSES));

        $total = (clone $lines)->count();

        // An entry filed against NO period is treated as OPEN: it can be re-derived, which is the
        // half that matters here. Leaning the other way would overstate the permanent damage and
        // train the operator to dismiss the warning.
        $closed = (clone $lines)
            ->whereHas('entry', fn ($q) => $q->whereHas(
                'period',
                fn ($p) => $p->where('status', '!=', 'open'),
            ))
            ->count();

        return ['total' => $total, 'open' => $total - $closed, 'closed' => $closed];
    }

    /**
     * The sentence an operator reads before re-pointing a role — or null when nothing is posted.
     *
     * Null rather than "0 documents" deliberately: a warning shown on a mapping nothing has used is
     * a warning trained away before the one that matters.
     */
    public static function warningFor(?int $accountId): ?string
    {
        $counts = self::on($accountId);

        if ($counts['total'] === 0) {
            return null;
        }

        return $counts['closed'] > 0
            ? __('admin.helpers.posting_role_repoint_closed', $counts)
            : __('admin.helpers.posting_role_repoint_open', $counts);
    }

    /** Does this account carry anything at all? Used where only the yes/no matters. */
    public static function hasPostings(?int $accountId): bool
    {
        return self::on($accountId)['total'] > 0;
    }
}
