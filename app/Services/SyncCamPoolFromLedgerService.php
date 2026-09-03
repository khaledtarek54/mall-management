<?php

namespace App\Services;

use App\Contracts\BillableAgreement;
use App\Models\CamExpensePool;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Derive a recovery pool's two totals from the records that already hold them (stories RC-01/RC-05).
 *
 * **What was wrong.** Both numbers on `cam_expense_pools` were typed by a human.
 * `total_actual_expense` was re-keyed from a spreadsheet built off the vendor bills, so no tenant
 * charge could drill through to the invoices behind it and a single transcription slip re-billed
 * the whole mall. `total_estimated_collected` was one portfolio figure sliced pro-rata, which means
 * `estimated_paid` on an allocation was never what that tenant actually paid — it was their share
 * of a number somebody kept roughly equal to what had been billed.
 *
 * **It WRITES the totals rather than exposing a live query, deliberately.** A journal entry posted
 * late — a vendor bill that arrives in March for December — must not silently restate allocations
 * that have already been billed to tenants. Syncing is an act with a date on it
 * (`expense_synced_at`), and a reconciled or closed pool refuses it outright, which is the same
 * discipline as the frozen-share re-run guard.
 *
 * **Both bases default to `stated`** on every pool that already exists, so nothing is restated.
 */
class SyncCamPoolFromLedgerService
{
    /**
     * @return array{expense: ?float, estimate: ?float} the totals written, null where the basis is `stated`
     */
    public function sync(CamExpensePool $pool): array
    {
        if (in_array($pool->status, ['reconciled', 'closed'], true)) {
            throw new InvalidArgumentException(
                "Pool #{$pool->id} is '{$pool->status}'; its totals are frozen. Re-open the year to re-source them."
            );
        }

        $expense = $pool->expense_basis === CamExpensePool::BASIS_LEDGER
            ? $this->actualFromLedger($pool)
            : null;

        $estimate = $pool->estimate_basis === CamExpensePool::BASIS_BILLED
            ? $this->estimateFromInvoices($pool)
            : null;

        if ($expense === null && $estimate === null) {
            return ['expense' => null, 'estimate' => null];
        }

        DB::transaction(function () use ($pool, $expense, $estimate): void {
            $pool->forceFill(array_filter([
                'total_actual_expense' => $expense,
                'total_estimated_collected' => $estimate,
                'expense_synced_at' => now(),
            ], fn ($v) => $v !== null))->save();
        });

        return ['expense' => $expense, 'estimate' => $estimate];
    }

    /**
     * The year's posted expense on the pool's chosen accounts, for the pool's property.
     *
     * Debits less credits, so a credit note from a vendor or a correcting reversal reduces the pool
     * exactly as it reduces the ledger — a debits-only sum would recover money from tenants that
     * the landlord had already been refunded.
     *
     * **Reportable entries: `posted` AND `void`** ({@see JournalEntry::REPORTABLE_STATUSES}).
     *
     * This line used to read "Only POSTED entries. A draft journal is not an expense yet, and a
     * voided one never was." The first half is right and the second is exactly backwards, and that
     * belief is what made this the most dangerous consumer of the rule: `void()` does not erase an
     * entry, it posts a sign-flipped reversal and marks the original `void`, leaving the original's
     * lines in place. Counting only `posted` therefore keeps the reversal and drops the original.
     *
     * Because this figure is persisted into `cam_expense_pools.total_actual_expense` and is the
     * basis tenants are billed off, a cancelled 100,000 cleaning bill drove the pool to −100,000
     * and the annual true-up would have issued **every tenant in the pool a credit note** for their
     * share of money the landlord never over-collected. Nothing caught it: the entry balances, the
     * AR/AP tie-out does not watch expense accounts, and `wouldChange()` is false.
     */
    public function actualFromLedger(CamExpensePool $pool): float
    {
        $accountIds = $pool->ledgerAccounts()->pluck('ledger_accounts.id');

        if ($accountIds->isEmpty()) {
            return 0.0;
        }

        $start = CarbonImmutable::create((int) $pool->period_year, 1, 1)->toDateString();
        $end = CarbonImmutable::create((int) $pool->period_year, 12, 31)->toDateString();

        $net = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.ledger_account_id', $accountIds)
            // posted AND void — see JournalEntry::REPORTABLE_STATUSES. void() leaves the
            // original's lines in place and posts a reversal, so counting only `posted` keeps the
            // reversal and drops the original: a cancelled 100,000 bill drove this basis to
            // −100,000 and would have credit-noted every tenant in the pool for a share of money
            // the landlord never over-collected.
            ->whereIn('journal_entries.status', JournalEntry::REPORTABLE_STATUSES)
            // The pool belongs to ONE property, and so must its costs. A line stamped with another
            // asset — or with none — is not this mall's service charge, and recovering it here
            // would bill this mall's tenants for the mall next door.
            ->where('journal_lines.asset_id', $pool->asset_id)
            ->whereDate('journal_entries.entry_date', '>=', $start)
            ->whereDate('journal_entries.entry_date', '<=', $end)
            ->selectRaw('sum(journal_lines.debit) - sum(journal_lines.credit) as net')
            ->value('net');

        return round((float) $net, 2);
    }

    /**
     * The variable fraction of a ledger-sourced pool (story RC-04).
     *
     * Posted spend on the accounts marked `variable` over posted spend on all of them, so the split
     * follows the money rather than a count of accounts — one big variable account and nine tiny
     * fixed ones is a mostly-variable pool.
     *
     * An account with no nature reads as FIXED (the pivot default), which is the conservative
     * reading for this purpose: it grosses nothing and charges tenants nothing extra.
     */
    public function variableShareFromLedger(CamExpensePool $pool): float
    {
        $variableIds = $pool->ledgerAccounts
            ->filter(fn ($a) => $a->getAttribute('pivot')?->getAttribute('cost_nature') === 'variable')
            ->pluck('id');

        if ($variableIds->isEmpty()) {
            return 0.0;
        }

        $total = $this->actualFromLedger($pool);

        if ($total <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->netForAccounts($pool, $variableIds) / $total));
    }

    /**
     * Posted debits less credits on a subset of the pool's accounts, for its property and year.
     *
     * One definition, three callers (the pool total, the variable split, the controllable split) —
     * three copies of this query would eventually disagree about what "posted" or "this property"
     * means, and the splits would stop summing to the total they divide.
     *
     * @param  Collection<int, int>  $accountIds
     */
    /**
     * What a NAMED SUBSET of this pool's accounts holds for the year — the amount a lease's own
     * exclusion clause carves out of its share (slice 3).
     *
     * Narrowed to the pool's OWN accounts on purpose: a term naming an account this pool does not
     * contain must subtract nothing, not reach into the ledger for it. An operator who retires an
     * account from a pool would otherwise go on excluding it for ever, invisibly.
     *
     * @param  list<int>  $accountIds
     */
    public function netForPoolAccounts(CamExpensePool $pool, array $accountIds): float
    {
        $inThisPool = $pool->ledgerAccounts->pluck('id')->intersect($accountIds);

        return $inThisPool->isEmpty() ? 0.0 : round($this->netForAccounts($pool, $inThisPool->all()), 2);
    }

    private function netForAccounts(CamExpensePool $pool, $accountIds): float
    {
        $start = CarbonImmutable::create((int) $pool->period_year, 1, 1)->toDateString();
        $end = CarbonImmutable::create((int) $pool->period_year, 12, 31)->toDateString();

        return (float) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.ledger_account_id', $accountIds)
            // posted AND void — see JournalEntry::REPORTABLE_STATUSES.
            ->whereIn('journal_entries.status', JournalEntry::REPORTABLE_STATUSES)
            ->where('journal_lines.asset_id', $pool->asset_id)
            ->whereDate('journal_entries.entry_date', '>=', $start)
            ->whereDate('journal_entries.entry_date', '<=', $end)
            ->selectRaw('sum(journal_lines.debit) - sum(journal_lines.credit) as net')
            ->value('net');
    }

    /**
     * The controllable fraction of a ledger-sourced pool (story RC-07).
     *
     * Posted spend on the accounts flagged controllable over posted spend on all of them. The pivot
     * defaults to TRUE, so a pool nobody has classified is fully controllable and a
     * controllable-scoped cap behaves exactly like the unscoped cap it replaces.
     */
    public function controllableShareFromLedger(CamExpensePool $pool): float
    {
        $ids = $pool->ledgerAccounts
            ->filter(fn ($a) => (bool) ($a->getAttribute('pivot')?->getAttribute('is_controllable') ?? true))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0.0;
        }

        $total = $this->actualFromLedger($pool);

        if ($total <= 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $this->netForAccounts($pool, $ids) / $total));
    }

    /**
     * What this pool actually invoiced its PARTICIPANTS as estimate during the year — tenants under
     * a lease and owners under a صيانة assessment alike.
     *
     * This is the number the reconciliation should compare against, and it was never available: the
     * pool held one hand-typed figure that a human kept roughly equal to the billing. Never-issued,
     * cancelled and fully credited invoices are excluded; a written-off one is NOT, because it was
     * billed and asked for. See {@see billedServiceChargeQuery()} for why each.
     *
     * Scoped to the pool's participants, off the same two queries `generateAllocations()` picks its
     * allocation targets with — one definition, so the estimate and the allocation cannot disagree
     * about who is in the pool.
     */
    public function estimateFromInvoices(CamExpensePool $pool): float
    {
        $start = CarbonImmutable::create((int) $pool->period_year, 1, 1)->toDateString();
        $end = CarbonImmutable::create((int) $pool->period_year, 12, 31)->toDateString();

        $billed = (float) $this->billedServiceChargeQuery($pool, $start, $end)->sum('invoice_items.amount');

        // Floored, exactly as the cost basis is (`CamReconciliationService::…` uses
        // `max(0.0, $basis - $excluded)`). Several partial credits against one invoice, or an
        // operator-typed credit line larger than the charge it relieves, can subtract more than was
        // billed — and a NEGATIVE `estimated_paid` does not read as an error anywhere: it stores in
        // a signed decimal, prints on the tenant's CAM statement, and makes the true-up bill the
        // credit back. Nobody was ever billed a negative estimate, so the floor cannot hide a real
        // figure; it can only stop an impossible one.
        return round(max(0.0, $billed - $this->creditedBack($pool, $start, $end)), 2);
    }

    /**
     * The same figure for ONE participant — what they were actually billed in estimates.
     *
     * Used by the reconciliation when `estimate_basis = billed`, which is what makes the estimate
     * reconciled and the estimate billed the same number by construction rather than by diligence.
     */
    public function estimateBilledFor(CamExpensePool $pool, BillableAgreement $agreement): float
    {
        $start = CarbonImmutable::create((int) $pool->period_year, 1, 1)->toDateString();
        $end = CarbonImmutable::create((int) $pool->period_year, 12, 31)->toDateString();

        // Filtered by whichever agreement raised the invoices — a lease's service-charge estimate,
        // or a unit OWNER's monthly صيانة. They are the same economic act, recovery of common cost,
        // billed under the same charge type, which is what makes an owner a participant rather than
        // a second parallel system — and until 2026-09-02 the query could not reach him at all,
        // because its participant filter named `invoices.lease_id` and an assessment invoice carries
        // a null one.
        $link = $agreement->invoiceLinkAttributes();
        $column = array_key_first(array_filter($link, fn ($v) => $v !== null));

        $billed = (float) $this->billedServiceChargeQuery($pool, $start, $end)
            ->where('invoices.'.$column, $link[$column])
            ->sum('invoice_items.amount');

        // Floored, exactly as the cost basis is (`CamReconciliationService::…` uses
        // `max(0.0, $basis - $excluded)`). Several partial credits against one invoice, or an
        // operator-typed credit line larger than the charge it relieves, can subtract more than was
        // billed — and a NEGATIVE `estimated_paid` does not read as an error anywhere: it stores in
        // a signed decimal, prints on the tenant's CAM statement, and makes the true-up bill the
        // credit back. Nobody was ever billed a negative estimate, so the floor cannot hide a real
        // figure; it can only stop an impossible one.
        return round(max(0.0, $billed - $this->creditedBack($pool, $start, $end, [$column => $link[$column]])), 2);
    }

    /**
     * What was CREDITED BACK out of those estimates — the term this figure was missing entirely.
     *
     * The billed sum was gross of every credit note. A FULLY credited invoice was at least caught by
     * the `credited` status, but a PARTIAL credit moves no status at all, so no filter written at the
     * invoice level could ever see one — and partial is the common case:
     * `CreditUnearnedBillingService::isTimeApportioned()` returns true for exactly a monthly,
     * not-in-arrears `service_charge`, which is what every mid-period move-out and every mid-year
     * resale credits. The pool believed it had collected money it had given back, and the annual
     * true-up under-charged by that amount, silently, per tenant.
     *
     * Matched on the credit LINE's own charge code, which is why `credit_note_items.type` had to
     * exist (SW-216): a credit note points at an invoice, and a pool needs to know how much of a
     * CHARGE came back. A line that does not state its type is not netted — null means *not stated*,
     * and guessing is what apportioning across line types would be.
     *
     * `onTheBooks()` is the same scope every other reader uses: a draft note was never issued, and a
     * void one was reversed.
     *
     * @param  array<string, mixed>  $link  the agreement's own invoice link, or [] for the whole pool
     */
    private function creditedBack(CamExpensePool $pool, string $start, string $end, array $link = []): float
    {
        return round((float) CreditNoteItem::query()
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_items.credit_note_id')
            ->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            // The same DERIVATION `CreditNote::scopeOnTheBooks()` uses — excluded by exception, never
            // allowlisted, so a status this class has not heard of counts and has to be excluded
            // deliberately. (The scope itself cannot be used here: this query is rooted on the
            // ITEM, and the notes arrive through a join.)
            ->whereNotIn('credit_notes.status', array_keys(CreditNote::NOT_ON_THE_BOOKS))
            ->whereIn('credit_note_items.type', $pool->estimateChargeCodes())
            ->where(fn ($q) => $q
                ->whereIn('invoices.lease_id', $pool->participantLeaseQuery()->select('leases.id'))
                ->orWhereIn('invoices.unit_ownership_id', $pool->participantOwnershipQuery()->select('unit_ownerships.id')))
            ->whereNotIn('invoices.status', self::INVOICE_NOT_BILLED)
            ->whereDate('invoices.period_start', '>=', $start)
            ->whereDate('invoices.period_start', '<=', $end)
            ->when($link !== [], fn ($q) => $q->where('invoices.'.array_key_first($link), reset($link)))
            ->sum('credit_note_items.amount'), 2);
    }

    /**
     * What THIS pool billed ITS participants as estimate, over the reconciled year.
     *
     * Both qualifiers were missing until 2026-08-11, and each on its own produced a wrong number:
     *
     *  - **Whose charge codes.** A single global `['service_charge']` served every pool, so a
     *    property running a `cam` pool and a `tax` pool had both subtract the same billed service
     *    charge. The tax pool reconciled to (allocated 20,000 − estimate 100,000) = −80,000 and
     *    issued a credit note, auto-applied FIFO against live AR.
     *  - **Whose tenants.** No participant filter at all, so an area-scoped pool subtracted the
     *    whole property's billing from one zone's allocation.
     *
     * Both now come from the pool. An undeclared non-`cam` pool is REFUSED rather than defaulted:
     * the billed basis is a claim about what was billed, and a pool that cannot say what it bills
     * has no business making it. A refusal costs the operator a form field; the guess cost them a
     * credit note.
     *
     * @return Builder<InvoiceItem>
     */
    /**
     * The invoice statuses that never represent money BILLED, for both halves of the estimate.
     *
     * **One list, read by the billed query AND by `creditedBack()`, and that is the whole of it.**
     * The two are two sides of one subtraction, so a status excluded from one and not the other is
     * relief counted twice. That is not hypothetical: the first pass of SW-216 dropped `credited`
     * from the billed side only, and a fully credited 30,000 service-charge invoice came back at
     * **−30,000** — `cam_allocations.estimated_paid` is a signed decimal, so it stored silently, the
     * tenant's CAM statement printed it, and the true-up billed the credit back.
     *
     * `draft` was never raised. `cancelled` left the books and `recomputeTotals()` zeroes it.
     * `credited` was billed and then fully reversed, so BOTH the invoice and its credits drop out
     * together and the pair nets to nothing — which is also why netting by line does not change this
     * membership: it is the PARTIAL credit, which moves no status at all, that no status filter
     * could ever see, and that is the one SW-216 exists for.
     *
     * `written_off` is deliberately NOT here: a write-off forgives a debt that WAS billed and the
     * tenant WAS asked for.
     */
    private const INVOICE_NOT_BILLED = ['draft', 'cancelled', 'credited'];

    private function billedServiceChargeQuery(CamExpensePool $pool, string $start, string $end)
    {
        $codes = $pool->estimateChargeCodes();

        if ($codes === []) {
            throw new \DomainException(__('admin.cam.errors.estimate_codes_required', [
                'pool' => $pool->label(),
            ]));
        }

        return InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            // A participant is a LEASE **or an OWNERSHIP**, and the second branch was missing.
            // An owner's assessment invoice carries a null `lease_id`, which `whereIn` never
            // matches, so on `estimate_basis = billed` every owner's `estimated_paid` was 0.00 and
            // the annual true-up billed his whole year's share a second time.
            //
            // **Grouped**, because AND binds tighter than OR: written flat this compiles to
            // `lease_in OR (ownership_in AND status AND type AND period)`, so it is the LEASE branch
            // that escapes — every invoice of every participant lease, at any status, of any item
            // type, in any year. `CamPoolEstimateScopeTest`'s tax-pool case is what catches that.
            ->where(fn ($q) => $q
                ->whereIn('invoices.lease_id', $pool->participantLeaseQuery()->select('leases.id'))
                ->orWhereIn('invoices.unit_ownership_id', $pool->participantOwnershipQuery()->select('unit_ownerships.id')))
            // **The question is "billed, and not reversed" — and the old list answered neither.**
            //
            //  - `draft` is the COLUMN DEFAULT, so a never-issued invoice is the normal product of
            //    any create that omits a status. Counting one inflates what the pool believes it
            //    collected, which understates the true-up — or, past the pool's total, mints a
            //    credit note for money nobody was ever asked for.
            //  - `cancelled` never reached the books at all.
            //  - `credited` is the terminal paid-by-credit-note state: billed, then REVERSED. It was
            //    counted GROSS, so a fully credited 100,000 service charge told the pool it had
            //    collected 100,000 it did not. Excluded, which is also the list
            //    `BooksReconciliationService` and `AssetStatementPdfService` already use.
            //  - **`written_off` is IN, and it was the one being excluded.** A write-off forgives a
            //    debt that WAS billed and the tenant WAS asked for; dropping it makes the true-up
            //    re-charge money the operator already invoiced and then forgave — SW-135's own shape
            //    through the opposite door. Measured: 120,000 billed with one 10,000 invoice written
            //    off against a 150,000 share gave a true-up of 40,000 where 30,000 is owed. It was a
            //    CLIFF as well as a rule, because `WriteOffInvoiceService` only moves the status on a
            //    FULL write-off — so 9,999.99 counted in full and 10,000.00 counted as nothing.
            //
            // **A PARTIAL credit note moves no status at all**, so no filter written here can see
            // one — and every mid-period move-out produces one against exactly these lines. Since
            // SW-216 `creditedBack()` nets those by LINE, off the same status list this query uses
            // (`INVOICE_NOT_BILLED`); a second list here is relief counted twice, which is what the
            // first pass of that fix actually did.
            ->whereNotIn('invoices.status', self::INVOICE_NOT_BILLED)
            ->whereIn('invoice_items.type', $codes)
            // Keyed on the period the invoice COVERS, not the day it was raised: a December invoice
            // issued on 2 January belongs to the year the tenant occupied, which is the year being
            // reconciled.
            ->whereDate('invoices.period_start', '>=', $start)
            ->whereDate('invoices.period_start', '<=', $end);
    }
}
