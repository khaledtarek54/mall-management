<?php

namespace App\Support;

use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Services\Accounting\AccountResolver;
use Illuminate\Support\Facades\DB;

/**
 * **What security deposit does the operator actually hold?** — asked portfolio-wide.
 *
 * `Lease::depositHeld()` answers it for ONE tenancy. This answers it for a property or the whole
 * portfolio, off the same two components, because the deposit register is a balance-sheet screen
 * and had no way to ask.
 *
 * ## Why this class exists
 *
 * A deposit reaches the operator by two roads, and until 2026-08-18 only one of them left a trace
 * anybody could see:
 *
 *  1. **Recorded directly** — a `DepositTransaction`, written after the money arrived. This is the
 *     legacy road, and the only one the register and the lease's Deposits tab ever read.
 *  2. **Billed and settled** — `BillSecurityDepositService` raises an invoice with a
 *     `security_deposit` line (`Dr AR / Cr Deposits Held`); the tenant pays it (`Dr Bank / Cr AR`).
 *     The pair nets to exactly what road 1 posts in one step. This is now the RECOMMENDED road —
 *     it is the one that asks the tenant for the money — and it writes no `deposit_transactions`
 *     row at all.
 *
 * So the register showed 390,000 while the `deposits_held` liability stood at 534,000, and the
 * missing 144,000 was a deposit that had been billed, paid, and correctly booked. The operator's
 * one screen for "what do we owe back?" understated the liability, and nothing reconciled the two.
 *
 * ## Why this DERIVES rather than writing the missing row
 *
 * Writing a `DepositTransaction` when a deposit line settles is the intuitive fix and the wrong
 * one, twice over. The invoice has already credited `deposits_held`, so a receipt row would post
 * the liability a second time. And settlement is not a one-shot event — a part payment, a credit
 * note, a void or a write-off all move it — so the row would be a stored copy of a derived number,
 * needing permanent reconciliation against the thing it was copied from. That is the second truth
 * about the same money the AR invariants exist to forbid, and the same reason
 * {@see InvoiceItemSettlement} never stores a per-line balance.
 *
 * **The per-lease answer and this one read ONE seam** ({@see DepositBilling}), which they did not
 * until 2026-09-02: the invoice-status filter was written out four times, drifted, and the register
 * and the lease page reported different money. That the aggregate IS the sum of the parts is now a
 * structural property rather than a promise, and `ADepositIsHeldOnlyForMoneyReceivedTest` asks it.
 *
 * @see Lease::depositHeld() the per-lease answer, of which this is the aggregate
 */
class DepositHoldings
{
    /**
     * Deposits recorded directly as movements: receipts, less refunds, forfeits and anything
     * already netted against the tenant's invoices.
     *
     * Only `recorded` rows count — a draft is an intention, and holding intentions is how a
     * landlord refunds money it never received.
     */
    public static function recorded(?array $assetIds = null): float
    {
        $movements = DepositTransaction::query()->where('status', 'recorded');
        $applied = DepositApplication::query();

        if ($assetIds !== null) {
            $movements->whereIn('asset_id', $assetIds);
            $applied->whereIn('asset_id', $assetIds);
        }

        $sum = fn (string $type) => (float) (clone $movements)->where('type', $type)->sum('amount');

        return round($sum('receipt') - $sum('refund') - $sum('forfeit') - (float) $applied->sum('amount'), 2);
    }

    /**
     * Deposits billed on an invoice and SETTLED by the tenant.
     *
     * The settlement, never the line total: an unpaid deposit invoice is a receivable, not money in
     * the bank, and counting it as held would refund at move-out what was never received.
     *
     * **Read through {@see DepositBilling}, which is the point.** This method and its sibling below
     * carried the status list as two LITERALS while `Lease` carried it as a constant — a third and
     * fourth copy of the same filter — so the aggregate above the deposit register and the figure on
     * the lease page could disagree about the same money, and did: with a deposit invoice written
     * off after part payment the lease read *"held 60,000"* and this read **0.00**, under a red
     * GL-gap stat on the widget beside it. The `Lease` constant's own docblock had warned that *"a
     * filter written twice is a filter that drifts"* and named only two of the four places it was
     * written.
     */
    public static function billedAndSettled(?array $assetIds = null): float
    {
        $invoices = DepositBilling::receiptQuery()->with(['items', 'writeOffs']);

        if ($assetIds !== null) {
            $invoices->whereIn('asset_id', $assetIds);
        }

        $settled = 0.0;

        foreach ($invoices->get() as $invoice) {
            $settled += DepositBilling::heldOn($invoice);
        }

        return round($settled, 2);
    }

    /**
     * Deposits BILLED and not yet settled — a receivable, and the GL's counterpart to it.
     *
     * Not held, and deliberately not part of {@see held()}: nothing has been received, so nothing
     * can be refunded. It exists because `InvoiceJournalizer` credits `deposits_held` at ISSUE
     * (Dr Tenant Receivables / Cr Deposits Held — the correct entry for the billing rail), while
     * `billedAndSettled()` counts only what the tenant has actually paid. Both are right, and for
     * the whole window between issuing a deposit invoice and its payment they differ by exactly
     * this figure.
     *
     * Without it the weekly `billing:reconcile` reported a books discrepancy on every deposit in
     * flight — measured, a 150,000 deposit billed and unpaid moved the GL and not the register, and
     * the check failed until the payment landed (pre-staging QA, F-11). With terms of 7 days and a
     * Friday sweep, a deposit billed on a Thursday failed it every time. A check that cries wolf is
     * a check people switch off.
     */
    public static function billedAndOutstanding(?array $assetIds = null): float
    {
        $invoices = DepositBilling::claimQuery()->with(['items', 'writeOffs']);

        if ($assetIds !== null) {
            $invoices->whereIn('asset_id', $assetIds);
        }

        $outstanding = 0.0;

        foreach ($invoices->get() as $invoice) {
            // The line's OWN outstanding, from the same derivation `billedAndSettled()` reads — so
            // the two can never disagree about what a part-settled deposit line means — net of any
            // write-off that reaches it, because a forgiven amount is no longer in flight.
            $outstanding += DepositBilling::claimedOn($invoice);
        }

        return round($outstanding, 2);
    }

    /** Both roads — what the operator holds, and therefore owes back. */
    public static function held(?array $assetIds = null): float
    {
        return round(self::recorded($assetIds) + self::billedAndSettled($assetIds), 2);
    }

    /**
     * What the `deposits_held` control account should read: everything held, PLUS everything billed
     * and still owed, because the ledger recognises the liability at issue.
     *
     * The tie-out compares against THIS, not against {@see held()} — the register answers "what do
     * we owe back today", the ledger answers "what have we recognised", and the second legitimately
     * runs ahead of the first.
     */
    public static function expectedGlBalance(?array $assetIds = null): float
    {
        return round(self::held($assetIds) + self::billedAndOutstanding($assetIds), 2);
    }

    /**
     * The `deposits_held` liability as the GENERAL LEDGER has it — the independent second opinion.
     *
     * Derived from posted journal lines rather than from either component above, which is the whole
     * point: comparing `held()` against this catches a deposit that moved on one road and not in
     * the books. Null when the role is unmapped (an unseeded chart), so a caller can say "not
     * comparable" rather than report a false gap.
     */
    public static function glBalance(?array $assetIds = null): ?float
    {
        $ids = [];

        if ($assetIds === null) {
            // Portfolio-wide: EVERY account the role points at — the global default AND each
            // per-mall override. Reading only the global one is what made the deposits tie-out
            // permanently red the moment a mall got its own `deposits_held` mapping (SW-143): the
            // register counts every property's deposits, so the ledger side has to as well.
            //
            // One deliberate change of behaviour rides with it. A role mapped to a SUMMARY or
            // RETIRED account no longer reads as "not comparable": `accountsFor()` does not
            // re-check postability, so the account is counted, reads zero, and the gap is reported
            // — the honest answer, because the deposits are still being taken while nothing can
            // post the liability. An UNMAPPED role still yields nothing and still returns null.
            $ids = array_keys(app(AccountResolver::class)->accountsFor('deposits_held'));
        } else {
            foreach ($assetIds as $assetId) {
                try {
                    $ids[] = app(AccountResolver::class)->id('deposits_held', $assetId);
                } catch (\Throwable) {
                    // Unmapped for this property — not a discrepancy, nothing to compare against.
                }
            }
        }

        if ($ids === []) {
            return null;
        }

        $lines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.ledger_account_id', array_unique($ids))
            // REPORTABLE_STATUSES, not 'posted'. Voiding does not erase an entry — it posts a
            // sign-flipped reversal and marks the original `void`, leaving its lines in place. The
            // pair nets to zero only if BOTH are counted, and `LedgerPoster::sync()` voids on every
            // re-derive, so this is the ledger's normal operating mode rather than an edge case.
            // Filtering to `posted` here would keep each reversal and drop each original, and a
            // re-derived deposit invoice would read as a NEGATIVE liability.
            ->whereIn('journal_entries.status', JournalEntry::REPORTABLE_STATUSES);

        if ($assetIds !== null) {
            $lines->whereIn('journal_entries.asset_id', $assetIds);
        }

        // A liability sits on the credit side, so credits less debits is what is still owed.
        return round((float) $lines->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as bal')->value('bal'), 2);
    }
}
