<?php

namespace App\Support;

use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\Invoice;
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
     * the bank, and counting it as held would refund at move-out what was never received. Cancelled,
     * credited and written-off invoices claim nothing.
     */
    public static function billedAndSettled(?array $assetIds = null): float
    {
        $invoices = Invoice::query()
            ->whereNotIn('status', ['cancelled', 'credited', 'written_off'])
            ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'))
            ->with('items');

        if ($assetIds !== null) {
            $invoices->whereIn('asset_id', $assetIds);
        }

        $settled = 0.0;

        foreach ($invoices->get() as $invoice) {
            $settled += (float) InvoiceItemSettlement::for($invoice)
                ->where('type', 'security_deposit')
                ->sum('settled');
        }

        return round($settled, 2);
    }

    /** Both roads — what the operator holds, and therefore owes back. */
    public static function held(?array $assetIds = null): float
    {
        return round(self::recorded($assetIds) + self::billedAndSettled($assetIds), 2);
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

        foreach ($assetIds ?? [null] as $assetId) {
            try {
                $ids[] = app(AccountResolver::class)->id('deposits_held', $assetId);
            } catch (\Throwable) {
                // Unmapped for this property — not a discrepancy, just nothing to compare against.
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
