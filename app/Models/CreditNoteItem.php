<?php

namespace App\Models;

use App\Models\Concerns\WordsItselfForItsReader;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[DeletionAllowed(reason: 'parent-managed: rebuilt with its credit note')]
// Both stop at the note, which now carries its own asset_id. The old
// `creditNote.lease.unit` tail broke the moment a note could belong to a unit-owner
// assessment: that note's lease_id is NULL, so the chain resolved to nothing and the row
// fell out of every property-scoped read. Same correction as Invoice/InvoiceItem.
#[PropertyOwned(via: 'creditNote')]
class CreditNoteItem extends Model
{
    use WordsItselfForItsReader;

    protected $fillable = [
        'credit_note_id',
        // WHICH charge this line credits — the charge code, exactly as `invoice_items.type` is
        // (SW-216). Nullable, and null means "not stated", which every reader treats as un-nettable
        // rather than guessing.
        'type',
        'description',
        // See `InvoiceItem` — the same line-narrative pair, because a credit note is read by the
        // same person as the invoice it reverses.
        'description_key',
        'description_data',
        'tax_code',
        'amount',
        'vat_rate',
        'tax_override_reason',
        'vat_amount',
        'total',
    ];

    protected $casts = [
        'description_data' => 'array',
        'amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    protected static function booted(): void
    {
        // The parent note's frozen deposit share follows its own lines (SW-238). Lines are written
        // at creation and are evidence afterwards, so this settles immediately and never moves
        // again — which is what lets the journalizer read a stable figure instead of re-deriving
        // one that could restate a posted entry.
        static::saved(fn (self $item) => $item->creditNote?->refreshDepositAmount());
        static::deleted(fn (self $item) => $item->creditNote?->refreshDepositAmount());

        // **This model had no hooks at all** until 2026-08-12 — no `booted`, no `saved`, nothing —
        // while its sibling `InvoiceItem` has carried the equivalent for as long as it has existed.
        //
        // So a credit note's header was derived in the BROWSER: `CreditNoteForm` dehydrates
        // `subtotal`/`vat_amount`/`total`/`balance` while the note is draft, and `CreditNoteService`
        // then trusted what arrived, its own comment asserting "the totals are already
        // item-derived" — true of the JavaScript and of nothing on the server. The result posts
        // `Dr Sales Returns / Cr AR` at a figure the document's own lines cannot reproduce, which
        // is a credit note an auditor cannot follow and a tenant cannot check.
        //
        // `CreditNote::recomputeFromItems()` already existed and already did the right thing,
        // recomputing VAT from each line's `amount × vat_rate` rather than the submitted
        // `vat_amount`. It was simply never called from the side that changes.
        $syncHeader = function (self $item): void {
            $note = $item->creditNote;

            if (! $note instanceof CreditNote) {
                return;
            }

            $note->recomputeFromItems();
            $note->saveQuietly();
        };

        // Deleted as well as saved: removing a line has to move the header down, or the note keeps
        // crediting money no line accounts for.
        static::saved($syncHeader);
        static::deleted($syncHeader);
    }
}
