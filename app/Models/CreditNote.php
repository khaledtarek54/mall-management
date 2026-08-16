<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\HidesDraftsFromTenant;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Services\CreditNoteService;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[NeverDeletable(correction: 'cancel the note — it un-applies against the original invoice')]
// Denormalized asset_id, like Invoice. The `lease.unit` chain answered NULL for a note
// against a unit-owner invoice, dropping it from every property-scoped read.
#[PropertyOwned]
#[PostingDateGuardedBy(guard: CreditNoteService::class)]
class CreditNote extends Model
{
    use AllocatesDocumentNumber, RefusesDeletionOfCommittedRecords;
    use HasFactory, HasSearchText, HidesDraftsFromTenant, LogsActivity, SoftDeletes;

    /**
     * The note number.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->number,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'invoice_id', 'tenant_id', 'total', 'applied_amount', 'balance', 'reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('credit_note');
    }

    protected $fillable = [
        'number',
        'asset_id',
        'tenant_id',
        'invoice_id',
        'lease_id',
        'status',
        'issue_date',
        'reason',
        'reason_notes',
        'subtotal',
        'vat_amount',
        'total',
        'applied_amount',
        'balance',
        'currency',
        'issued_by_user_id',
        'applied_at',
        'voided_at',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'applied_at' => 'datetime',
        'voided_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The property whose books this credit belongs to — denormalized, never inferred.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The property, from the invoice being credited or (for a standalone note) its lease.
     *
     * The invoice wins: its `asset_id` is populated by construction and is the ONLY answer for an
     * owner note, whose lease is null. `withTrashed()` on the lease fallback for the same reason the
     * invoice migration used raw SQL — a note against a terminated lease is a real credit in a real
     * mall, and the relation would scope exactly those rows out.
     */
    protected function deriveAssetId(): ?int
    {
        if ($this->invoice_id !== null) {
            $fromInvoice = Invoice::withTrashed()->whereKey($this->invoice_id)->value('asset_id');

            if ($fromInvoice !== null) {
                return (int) $fromInvoice;
            }
        }

        if ($this->lease_id === null) {
            return null;
        }

        return Lease::withTrashed()->whereKey($this->lease_id)->first()?->assetId();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CreditNoteApplication::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function hasBalance(): bool
    {
        return (float) $this->balance > 0 && in_array($this->status, ['issued', 'applied']);
    }

    /**
     * Re-derive the note's money fields from its authoritative persisted line items — VAT is
     * computed from each item's amount × vat_rate (never the submitted item vat_amount/total), so a
     * tampered form submit can't inflate the note's total (which the journalizer posts to the GL).
     */
    public function recomputeFromItems(): void
    {
        $items = $this->items()->get();
        $subtotal = round((float) $items->sum(fn (CreditNoteItem $i) => (float) $i->amount), 2);
        $vat = round((float) $items->sum(fn (CreditNoteItem $i) => round((float) $i->amount * (float) $i->vat_rate / 100, 2)), 2);

        $this->subtotal = $subtotal;
        $this->vat_amount = $vat;
        $this->total = round($subtotal + $vat, 2);
        $this->balance = round((float) $this->total - (float) $this->applied_amount, 2);
    }

    /**
     * The number prefix for this document's sequence — ONE definition, used by generateNumber()
     * and by the allocation lock key (see AllocatesDocumentNumber). Two copies would drift, and a
     * lock keyed on a prefix that no longer matches the sequence it guards protects nothing.
     */
    public static function numberPrefix(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $issueDate = $issueDate ? Carbon::instance($issueDate) : now();

        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('credit_note'), $assetCode, $issueDate->format('Ym'));
    }

    public static function generateNumber(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $prefix = static::numberPrefix($assetCode, $issueDate);

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::creating(function (self $note) {
            // The note's own property, settled once. Everything that used to walk
            // `lease -> unit -> asset` reads this column instead — that chain answers NULL for a
            // note against a unit-OWNER invoice, and a note with no property posts to the ledger
            // with no dimension: balanced, tied out, and invisible to that mall's P&L.
            //
            // A note raised with neither an invoice nor a lease is legitimately unscoped; it adopts
            // the property when `CreditNoteService::applyToInvoice()` binds it. So this derives when
            // it can and stays quiet when it cannot — unlike Invoice, which always has an agreement
            // and therefore always refuses a null.
            if ($note->asset_id === null) {
                $note->asset_id = $note->deriveAssetId();
            }

            if (empty($note->number)) {
                $assetCode = $note->asset?->code ?: 'AW';
                $note->number = $note->allocateDocumentNumber(
                    static::numberPrefix($assetCode, $note->issue_date),
                    fn (): string => static::generateNumber($assetCode, $note->issue_date),
                );
            }
            if (empty($note->currency)) {
                $note->currency = 'EGP';
            }
            if ($note->balance === null) {
                $note->balance = (float) ($note->total ?? 0) - (float) ($note->applied_amount ?? 0);
            }
        });

        // Finalized credit-note immutability guard (GL integrity — Phase 1). A note is
        // freely editable while draft; once issued it is a live sales-return posting:
        //   1. it cannot be reverted to draft (that would re-open the form-locked fields);
        //   2. its target/date (issue_date, tenant, invoice, lease) are immutable — the
        //      service only ever writes derived fields (applied_amount/balance/status).
        // The amounts + line items are frozen by the form lock (the derived totals aren't
        // guarded here to avoid float-round-trip false positives). Defense-in-depth behind
        // the form lock — closes the JS-tamper / API / tinker path.
        static::updating(function (self $note) {
            if ($note->getOriginal('status') === 'draft') {
                return; // draft is freely editable (and draft→issued must be allowed)
            }
            if ($note->status === 'draft') {
                throw new \DomainException('A finalized credit note cannot be returned to draft — void it and issue a new one instead.');
            }
            foreach (['issue_date', 'tenant_id', 'invoice_id'] as $field) {
                if ($note->isDirty($field)) {
                    throw new \DomainException("A finalized credit note's {$field} is immutable — void it and issue a new one.");
                }
            }
            // `asset_id` and `lease_id` may each be bound ONCE from null — a standalone note
            // adopting the property of the first invoice it settles (CreditNoteService::
            // applyToInvoice, so the sales-return posts to that property). Re-homing an
            // already-scoped note stays refused: `asset_id` IS the entry's property dimension, so
            // moving it books the reversal into another mall's P&L and another owner's statement.
            foreach (['asset_id', 'lease_id'] as $bindOnce) {
                if ($note->isDirty($bindOnce) && $note->getOriginal($bindOnce) !== null) {
                    throw new \DomainException("A finalized credit note's {$bindOnce} is immutable — void it and issue a new one.");
                }
            }
        });

        // Deleting a note whose credit is still APPLIED would strand the invoice's
        // credit_applied_amount (the Filament DeleteAction does NOT route through void(), which is
        // hidden for applied notes anyway) — the sweep voids the note's GL entry while the invoice
        // keeps counting the credit → permanent AR drift. Refuse; reverse the application first.
        static::deleting(function (self $note) {
            // Applies to force-delete too: the applications FK is cascadeOnDelete, so force-deleting an
            // applied note would drop its rows while the invoices keep credit_applied_amount (AR drift)
            // and orphan the note's GL entry. Reverse the application first, then delete.
            if ((float) $note->applied_amount > 0) {
                throw new \DomainException('Cannot delete a credit note whose credit is still applied — reverse the application first, then delete.');
            }
        });
    }
}
