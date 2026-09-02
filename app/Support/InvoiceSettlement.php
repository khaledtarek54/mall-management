<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * Which invoices may RECEIVE a settlement, and how much of one — the one place that answers it.
 *
 * Four channels settle an invoice ({@see Invoice::recomputeTotals()}): a captured payment, an
 * applied credit note, applied on-account tenant credit, and a netted security deposit. Each call
 * site carried its own opinion of which invoices are eligible, and they had drifted into five
 * different answers:
 *
 *   `CreditNoteService`                       allowlist `[issued, partially_paid, overdue]`
 *   `PaymentForm` (picker + auto-suggest)     denylist  `[cancelled, credited, written_off]`
 *   `ApplyDepositToInvoiceService::apply()`   denylist  `[cancelled, written_off]` — so it permitted a DRAFT
 *   `ApplyDepositToInvoiceService::settleOpenAr()`  allowlist `[issued, partially_paid, overdue]`
 *   `ApplyTenantCreditService`                only      `[cancelled]` — which the balance test beside it already caught
 *   `PostDatedChequeService::clear()`         nothing at all on the linked-invoice branch
 *   `PostDatedChequeService::settleOpenInvoices()`  allowlist, and capping at the raw balance
 *   `PostDatedChequeForm`                     allowlist, at LINK time only
 *
 * **What makes this a registry rather than a tidy-up: a WRITE-OFF deliberately leaves `balance`
 * standing.** `WriteOffInvoiceService` says so in writing — balance is derived from the four
 * channels and a write-off is not one of them — so every guard capping at `balance` alone caps at a
 * number the write-off never moved. `cancelled` has been safe only by ACCIDENT: `recomputeTotals()`
 * forces its balance to 0, so `min($amount, $balance)` is 0 with nobody asking about the status.
 * `written_off` is the one relieved status where that accident does not happen.
 *
 * Measured on the PDC path: write off an 11,400 invoice, then clear the cheque lodged against it
 * months earlier. The receipt allocates the full 11,400 — AR debited at issue, relieved by the
 * write-off (Dr Bad Debt / Cr AR), and relieved AGAIN by the receipt (Dr Bank / Cr AR). AR ends at
 * −11,400 for one debt while the bad-debt expense stands for money that was in fact collected. The
 * link is checked at LODGING and never re-asked at CLEARING, and months pass in between — so a
 * link-time filter is structurally incapable of answering a clear-time question.
 *
 * **This is a FLOOR, not a ceiling.** A channel may narrow further where its act differs — the
 * credit-note path additionally refuses a `disputed` invoice, and says why. What no channel may do
 * is be looser than this.
 *
 * **A write-off is still NOT a fifth settlement channel.** Nothing here reaches
 * `recomputeTotals()`. `settleableAmount()` caps money ARRIVING, which is a different question from
 * what has already settled — and the four-channel invariant is untouched.
 */
final class InvoiceSettlement
{
    /**
     * Statuses whose AR is no longer live — nothing may settle one — with the reason for each.
     *
     * A reason per row rather than a bare list, for the reason `DeletionPolicy` gives: whoever adds
     * the next status has to say which side it falls on, and why.
     */
    public const RELIEVED = [
        'draft' => 'Never posted — InvoiceJournalizer returns early on a draft, so no AR was ever debited. Cash against a draft credits a receivable that does not exist. (It used to also FLIP the document live: `draft` was not one of recomputeTotals() manual overrides, so the same recompute made an unissued invoice `paid` without it ever passing through IssueInvoiceService. Fixed 2026-09-02 — SW-215 — and this refusal stands on the first reason alone, which is the one that was never about a side effect.)',
        'cancelled' => 'Left the books. recomputeTotals() forces its balance to 0, which is what has silently protected this status until now — an accident of the arithmetic, not a guard.',
        'credited' => 'Settled in full by a credit note; that document already relieved the AR.',
        'written_off' => 'Relieved to bad debt (Dr Bad Debt / Cr AR). The balance deliberately STANDS, so this is the one status a balance-only cap cannot see. The remedy for a debt that pays after all is Reverse write-off — a recovery is booked as a recovery, not as a settlement stacked on top of the relief.',
    ];

    /** Statuses whose AR is live and may receive money, with the reason for each. */
    public const LIVE = [
        'issued' => 'The ordinary open receivable.',
        'partially_paid' => 'Part-settled; the rest is still owed.',
        'overdue' => 'Open and past due — what the whole collections surface is about.',
        'disputed' => 'A contested LINE does not stop the tenant PAYING the invoice, and Yardi collects against it. Note that CreditNoteService narrows further and refuses a disputed invoice: that is deliberate and is not drift. Paying is the tenant choice; applying credit is the operator spending a credit note against an amount still being argued about, and if the dispute resolves downward that credit was spent on money never owed. A channel may be stricter than this floor, with its reason stated.',
        'paid' => 'LIVE because a reversal re-opens it, and refusing on the status would refuse the receipt that arrives inside that window. Do NOT rely on "settleableAmount() is 0 anyway": that holds only while `balance` agrees with the status, and it need not — an invoice can carry `status = paid` with a standing balance. CreditNoteService therefore refuses it outright, because a credit note reduces what is OWED and nothing is.',
    ];

    /**
     * May this invoice receive a settlement at all?
     *
     * Excludes rather than allowlists — the direction `HidesDraftsFromTenant` argues for. A legacy
     * or imported status is still collectable, and refusing a real receipt is the worse failure.
     * What stops a NEW status shipping unclassified is the partition gate, not a run-time default.
     */
    public static function accepts(Invoice $invoice): bool
    {
        return ! array_key_exists((string) $invoice->status, self::RELIEVED);
    }

    /**
     * The most a NEW settlement may put on this invoice.
     *
     * `balance` net of what has already been written off — the SAME netting `WriteOffInvoiceService`
     * performs when it caps a second write-off. That fix taught the write-off side to net prior
     * write-offs and left the settlement side capping at the raw balance, so a 5,000 partial
     * write-off on a 20,000 invoice left all 20,000 allocatable while only 15,000 of AR remained.
     *
     * Reversed write-offs drop out on their own — the relation is a plain `hasMany` on a
     * soft-deleting model — so a recovered debt becomes settleable again with no second rule to keep
     * in step.
     */
    public static function settleableAmount(Invoice $invoice): float
    {
        // DELEGATED, not re-implemented. `Invoice::collectableBalance()` is the same arithmetic
        // asked as a different question — *what may still be collected* rather than *what may still
        // be put on this invoice* — and a second copy here was a third implementation of one
        // netting (with `collectableBalanceSql()` as the fourth). It also inherits that method's
        // eager-load preference for free, which matters now that `Invoice::isPayable()` asks this
        // per ROW on the portal's invoice table.
        return self::accepts($invoice) ? $invoice->collectableBalance() : 0.0;
    }

    /** The statuses nothing may settle — for a query that needs the list rather than the row. */
    public static function relievedStatuses(): array
    {
        return array_keys(self::RELIEVED);
    }
}
