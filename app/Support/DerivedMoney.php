<?php

namespace App\Support;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\VendorBill;

/**
 * Which money columns are DERIVED — and therefore may not be written by whoever posts the form.
 *
 * **The pattern this exists to stop:** a Filament field rendered `readOnly()` or `disabled()` and
 * then `->dehydrated()`. `readOnly`/`disabled` are HTML attributes — styling and a browser hint —
 * while `dehydrated()` is an explicit opt-IN to the submitted payload. Together they read like a
 * locked field and behave like an open one, and the model is the only place the difference can be
 * settled.
 *
 * Three live instances were found on 2026-08-12, each reachable by any holder of the relevant
 * `.edit` permission posting a crafted Livewire payload:
 *
 *  - **`invoices.balance`** — the corrective hook short-circuited unless the header was also dirty,
 *    so a payload changing balance ALONE persisted. The invoice then read settled in the portal, in
 *    AR aging (which filters `balance > 0`), in the overdue scan and on every collections screen,
 *    while the GL still carried the AR debit.
 *  - **`invoices.paid_amount`** — the same route to marking an invoice paid with no payment, credit
 *    note, tenant credit or deposit behind it.
 *  - **`invoices.number`** — rewritable on an issued invoice, re-labelling a tax document the tenant
 *    holds and the ETA may have seen.
 *
 * And one structural absence: **`CreditNoteItem` had no model hooks at all**, so a credit note's
 * header was derived in the browser and `CreditNoteService` trusted it verbatim — posting
 * `Dr Sales Returns / Cr AR` at a figure the document's own lines could not reproduce.
 *
 * **Every model with a fillable money column is classified here, and
 * `DerivedMoneyConformanceTest` fails on one that is not** — so a new module answers the question
 * rather than inheriting "the form said readOnly". The gate does not read the forms: it TAMPERS
 * with a committed record and asserts the value did not stick, which is the only claim that
 * matters and the only one a `->dehydrated()` cannot quietly invalidate.
 */
class DerivedMoney
{
    /**
     * Columns the SYSTEM owns: computed from lines, from settlements, or allocated on create.
     * A client-supplied value must be discarded or refused — never persisted.
     *
     * @var array<class-string, array<string, string>>
     */
    public const DERIVED = [
        Invoice::class => [
            'subtotal' => 'Σ of the line items — syncTotalsFromItems() re-derives it on any header write.',
            'vat_amount' => 'Σ of the lines\' VAT, at the rate each line was ISSUED at.',
            'total' => 'subtotal + vat_amount, and the figure the GL debits to AR.',
            'paid_amount' => 'the FOUR settlement channels; recomputeTotals() is the single source of truth and writes it through saveQuietly(), so it never reaches the guard — anything that does is a payload.',
            'credit_applied_amount' => 'the SECOND settlement channel. Fillable, and until 2026-08-23 in neither dirty-check list, so a payload touching it alone hit the early exit and stuck — the next recomputeTotals() then folded it into paid_amount and the invoice read part-settled with no credit note behind it. Same saveQuietly() reasoning as paid_amount: CreditNoteService writes it and persists through recomputeTotals(), so nothing legitimate reaches the guard.',
            'balance' => 'total − paid_amount. Drives AR aging, the overdue scan, the portal and every collections screen.',
            'number' => 'allocated once by AllocatesDocumentNumber; it identifies a tax document that exists outside this system.',
        ],

        CreditNote::class => [
            'subtotal' => 'Σ of the note\'s lines — recomputeFromItems(), fired by the CreditNoteItem hooks.',
            'vat_amount' => 'recomputed from each line\'s amount × vat_rate, never the submitted vat_amount.',
            'total' => 'subtotal + vat_amount, and what the journalizer reverses out of revenue.',
            'balance' => 'total − applied_amount.',
            'number' => 'allocated once, like an invoice\'s.',
        ],
    ];

    /**
     * Money columns that are legitimately OPERATOR-ENTERED, with the reason.
     *
     * Listed rather than omitted: "this one is typed by a human" is a decision, and an unlisted
     * model is indistinguishable from one nobody thought about.
     *
     * @var array<class-string, string>
     */
    public const ENTERED = [
        InvoiceItem::class => 'A line\'s `amount` and `vat_rate` are the operator\'s; its `total` is already re-derived from them by the model\'s own saving hook, and the header follows via syncTotalsFromItems().',

        CreditNoteItem::class => 'Same shape as InvoiceItem — the operator states what is being credited, and the note\'s header is re-derived from these lines (hooks added 2026-08-12; their absence was the bug).',

        Expense::class => 'A supplier receipt is transcribed, not computed: the operator reads `total` and `vat_amount` off the document in their hand. There is no line-item table to derive from, so there is nothing for the system to own.',

        VendorBill::class => 'Its `subtotal`/`vat_amount`/`total` are transcribed from the vendor\'s invoice for the same reason as Expense. `paid_amount`/`balance` ARE derived — but VendorBill::recompute() owns them and the model already refuses edits to a finalized bill outright (RefusesDeletionOfCommittedRecords + the finalized-bill updating guard), which is a stricter answer than reverting.',
    ];

    /** Every model this registry has an opinion about. @return array<int, class-string> */
    public static function classified(): array
    {
        return array_merge(array_keys(self::DERIVED), array_keys(self::ENTERED));
    }
}
