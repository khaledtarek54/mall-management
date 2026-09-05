<?php

namespace App\Support;

use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Services\Accounting\Journalizers\InvoiceJournalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * How much of a deposit BILLED on an invoice is really held, and how much is really still claimed.
 *
 * Voyager's model, and the right one: a deposit charged on an invoice is held only to the extent the
 * tenant settled the line, because an unpaid deposit invoice is a receivable and not money in the
 * bank. Atriom asked both halves of that question in FOUR places — twice on `Lease`, twice on
 * {@see DepositHoldings} — and answered them with a list of invoice STATUSES.
 *
 * ## Why a status list was never enough
 *
 * The figures underneath come from {@see InvoiceItemSettlement}, which derives every per-line number
 * from `invoices.paid_amount`. Status is a coarse proxy for an amount-level question, so it caught
 * only the terminal cases and missed every partial one — in both directions, both of them money:
 *
 *  - **A partial CREDIT NOTE inflated the pot.** `credit_applied_amount` is settlement channel two
 *    and feeds `paid_amount`, so crediting 40,000 of a 100,000 deposit invoice on which 60,000 cash
 *    had arrived left the invoice `paid` — never `credited` — and the pot read the whole 100,000.
 *    A move-out then refunded 40,000 the mall never received, outbound, with no recovery path.
 *  - **A partial WRITE-OFF made the shortfall un-re-billable.** `WriteOffInvoiceService` retires an
 *    invoice only when the write-off clears the remainder, so a partial one changes no status: the
 *    forgiven 10,000 went on counting as *already asked for*, the *Bill deposit* button stayed
 *    hidden, and the service refused with *"already billed 10,000.00"* — quoting money the operator
 *    themselves had forgiven. No path existed to ask for it again.
 *  - **And a FULL write-off erased what the tenant had paid**, which is the defect this class was
 *    written for: the invoice dropped out of the pot entirely and 60,000 of somebody's security
 *    quietly stayed with the mall.
 *
 * So both questions are answered from AMOUNTS here, and the status list shrinks to the two that
 * genuinely record neither a receipt nor a claim. The precedent is in this repo:
 * `BooksReconciliationService::arDiscrepancies()` added an explicit `InvoiceWriteOff` term for the
 * identical reason — *"the exclusion above only rescues invoices written off in FULL; every partial
 * one showed as an AR delta from the day it was booked, permanently, with no way to clear it."*
 *
 * ## One home, because four copies is what drifted
 *
 * `Lease` carried the list as a constant and `DepositHoldings` carried the same three strings as two
 * literals, so the aggregate above the deposit register and the figure on the lease page could —
 * and did — disagree by the whole of a written-off deposit. The register said *"held 0.00"* while
 * the lease said *"held 60,000"*. Both read this now.
 *
 * ## Attribution, and which way each error leans
 *
 * An invoice-level credit note or write-off is not pointed at a line (only payments carry an item
 * allocation — see {@see InvoiceItemSettlement}), so each has to be attributed. Each is attributed
 * in the direction that fails SAFELY, which is not the same direction for both:
 *
 *  - **Credit relief comes off the DEPOSIT line first.** Understating the holding shows as a deposit
 *    shortfall an operator can see and chase; overstating it refunds cash that never arrived. The
 *    same choice `InvoiceItemSettlement::TYPE_PRIORITY` already states for the deposit's position:
 *    *"between a wrong refund and a visible shortfall, take the shortfall."*
 *  - **A write-off comes off the deposit line LAST.** Understating the claim would let the deposit
 *    be billed a second time, which is the double-ask `BillSecurityDepositService` exists to
 *    prevent, so only the part of the write-off that exceeds every other outstanding line reduces
 *    what the deposit is still claimed at.
 */
class DepositBilling
{
    /**
     * Invoice statuses that record neither a receipt nor a claim.
     *
     * `cancelled` is the load-bearing one and must never leave this list: `recomputeTotals()` zeroes
     * a cancelled invoice's `paid_amount`, so its deposit line would read as fully OUTSTANDING and
     * be counted as a live claim. `credited` is belt-and-braces — the amount rule already nets a
     * fully-credited invoice to nothing — kept because it is a legal `ValueSets` value that imported
     * rows can carry with a `paid_amount` nobody re-derived.
     *
     * **`written_off` is deliberately NOT here**, in either direction. A write-off forgives what was
     * not paid; it does not un-pay what was, and it does not stop the forgiven part being asked for
     * again. Both of those are amount questions and are answered as such below.
     */
    public const EXCLUDED_STATUSES = ['cancelled', 'credited'];

    /** …and the claim question additionally ignores a draft: nothing has been asked for yet. */
    public const CLAIM_EXCLUDED_STATUSES = ['draft', 'cancelled', 'credited'];

    /**
     * Every invoice carrying a security-deposit line that can still record a receipt.
     *
     * @return Builder<Invoice>
     */
    public static function receiptQuery(): Builder
    {
        return Invoice::query()
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'));
    }

    /**
     * …and the ones that can still record a claim.
     *
     * @return Builder<Invoice>
     */
    public static function claimQuery(): Builder
    {
        return Invoice::query()
            ->whereNotIn('status', self::CLAIM_EXCLUDED_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('type', 'security_deposit'));
    }

    /** What of this invoice's deposit lines the landlord is actually holding. */
    public static function heldOn(Invoice $invoice): float
    {
        $settled = (float) InvoiceItemSettlement::for($invoice)
            ->where('type', 'security_deposit')
            ->sum('settled');

        // Credit relief is not money received. Off the deposit line first — see the class docblock.
        $credited = round((float) ($invoice->credit_applied_amount ?? 0), 2);

        return round(max($settled - $credited, 0), 2);
    }

    /** What of this invoice's deposit lines is still being asked for. */
    public static function claimedOn(Invoice $invoice): float
    {
        $lines = InvoiceItemSettlement::for($invoice);

        $deposit = (float) $lines->where('type', 'security_deposit')->sum('outstanding');
        $other = (float) $lines->where('type', '!=', 'security_deposit')->sum('outstanding');

        // A write-off reaches the deposit line only once every other outstanding line is exhausted.
        // Prefers an eager-loaded relation, because a lease list asks this once per row.
        $writtenOff = $invoice->relationLoaded('writeOffs')
            ? round((float) $invoice->writeOffs->sum('amount'), 2)
            : $invoice->writtenOffAmount();

        $reaching = max($writtenOff - $other, 0);

        return round(max($deposit - $reaching, 0), 2);
    }

    /**
     * How ONE write-off splits between relieving the deposit obligation and taking a bad debt
     * (SW-210) — `['deposit' => x, 'bad_debt' => y]`, summing to the write-off's own amount.
     *
     * **A write-off must reverse whatever the line originally CREDITED.** A revenue line credited
     * revenue, so accepting it as uncollectible is a bad-debt expense — that is what a write-off
     * means. A `security_deposit` line credited `deposits_held`, a LIABILITY (see
     * `InvoiceJournalizer::REVENUE_ROLE`), so no revenue was ever recognised against it: booking
     * bad-debt expense there charges the P&L for income it never took, AND leaves the obligation to
     * refund standing at its full billed figure for money the tenant never paid.
     *
     * **This READS the frozen figure; it does not derive one.** `deposit_amount` is computed once,
     * by {@see depositShareAtWriteOff()}, at the moment the write-off is taken. The entry a
     * write-off posts could not drift before SW-210 and must not start: a partial write-off leaves
     * the invoice LIVE, so a payment arriving later would move a re-derived split, and
     * `LedgerPoster::matches()` would then void and re-post an entry whose period may since have
     * closed — permanent, unclearable drift on `gl_in_sync` (SW-236, through another door).
     *
     * Legacy rows carry 0.00 and therefore post exactly what they posted before, which is the
     * prospective treatment the migration explains.
     *
     * @return array{deposit: float, bad_debt: float}
     */
    /**
     * Where a security-deposit line's credit went at ISSUE — resolved exactly as
     * `InvoiceJournalizer` resolves it, with the same shipped floor. Shared by the write-off
     * journalizer (SW-210) and the credit-note journalizer (SW-238), because a reversal never
     * re-classifies what it reverses, and two copies of the resolution is how the two reversal
     * doors come to debit different accounts for one obligation.
     */
    public static function depositPostingRole(): string
    {
        return ChargeCode::roleFor('security_deposit')
            ?? InvoiceJournalizer::REVENUE_ROLE['security_deposit'];
    }

    public static function writeOffSplit(InvoiceWriteOff $writeOff): array
    {
        $amount = round((float) $writeOff->amount, 2);

        if ($amount <= 0) {
            return ['deposit' => 0.0, 'bad_debt' => max($amount, 0.0)];
        }

        // Clamped to the write-off's own amount so a hand-edited column can never unbalance the
        // entry — the two debits must sum to the credit whatever is in the row.
        $deposit = min(max(round((float) $writeOff->deposit_amount, 2), 0.0), $amount);

        return ['deposit' => $deposit, 'bad_debt' => round($amount - $deposit, 2)];
    }

    /**
     * How much of a write-off ABOUT TO BE TAKEN reaches the deposit lines — the origination-time
     * computation whose answer {@see writeOffSplit()} then reads for ever.
     *
     * **The attribution is the one this class already states**, not a second rule: a write-off
     * reaches the deposit line only once every other outstanding line is exhausted, because
     * understating the claim re-opens the double ask `BillSecurityDepositService` exists to prevent.
     * Applied per ROW — write-offs land in sequence, so this one's share is what the running total
     * reaches THROUGH it, and anything earlier has already frozen its own.
     *
     * Ordered by `[entry_date, id]`: which of two write-offs takes the deposit relief decides which
     * month's P&L moves, so the operator's own dates lead and the id only breaks a tie. Reversed
     * write-offs drop out on their own — the relation soft-deletes, exactly as `settleableAmount()`
     * relies on.
     *
     * **Capped at what was actually CREDITED.** `InvoiceItemSettlement` works in gross figures
     * (`invoice_items.total`, VAT included) while `InvoiceJournalizer` credits `deposits_held` with
     * the NET `amount`. Should the accountant ever point the `security_deposit` charge code at a
     * taxable tax code, an uncapped gross debit would drive the liability negative by the line's VAT.
     */
    public static function depositShareAtWriteOff(Invoice $invoice, float $amount, ?int $excludeWriteOffId = null): float
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return 0.0;
        }

        $lines = InvoiceItemSettlement::for($invoice);

        $deposit = round((float) $lines->where('type', 'security_deposit')->sum('outstanding'), 2);

        if ($deposit <= 0) {
            return 0.0;
        }

        $other = round((float) $lines->where('type', '!=', 'security_deposit')->sum('outstanding'), 2);

        $earlier = round((float) $invoice->writeOffs()
            ->when($excludeWriteOffId !== null, fn ($q) => $q->whereKeyNot($excludeWriteOffId))
            ->sum('amount'), 2);

        $reachedBefore = min(max($earlier - $other, 0), $deposit);
        $reachedAfter = min(max($earlier + $amount - $other, 0), $deposit);

        $share = round(max($reachedAfter - $reachedBefore, 0), 2);

        // What the issue actually credited to `deposits_held` — net of tax. See above.
        $creditedNet = round((float) $invoice->items
            ->where('type', 'security_deposit')
            ->sum('amount'), 2);

        return round(min($share, $creditedNet, $amount), 2);
    }
}
