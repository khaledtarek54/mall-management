<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteItem extends Model
{
    protected $fillable = [
        'credit_note_id',
        'description',
        'tax_code',
        'amount',
        'vat_rate',
        'tax_override_reason',
        'vat_amount',
        'total',
    ];

    protected $casts = [
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
