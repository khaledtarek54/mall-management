<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\HidesDraftsFromTenant;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Services\CreditNoteService;
use App\Support\ActivityLogging;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use App\Support\PostingDate;
use App\Support\Translate;
use Illuminate\Database\Eloquent\Builder;
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
        return ActivityLogging::for($this, 'credit_note');
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

    /**
     * Give a system-raised credit note the ONE line that says what it credits.
     *
     * **Every service-created credit note had no lines at all (fixed 2026-08-17).** Both creators —
     * the termination unearned-credit and the CAM over-recovery credit — wrote header totals and
     * nothing else, so the document rendered as a Description/Amount/VAT/Total table with an EMPTY
     * BODY, on screen and in the PDF. Only the admin form, whose repeater writes the relation, ever
     * produced an itemised note.
     *
     * That matters more here than on most documents: a credit note is what a tenant uses to REVERSE
     * input VAT they have already claimed, and this one asked them to do it against a blank table.
     * It is the same document the seller-particulars work made identifiable five days earlier — a
     * credit note nobody can read is not much better than one nobody can attribute.
     *
     * A single line is deliberate. These notes credit one thing (a period's unearned billing, a
     * year's over-recovery), and splitting a derived figure into invented sub-lines would state more
     * than the service actually knows.
     *
     * **`$taxCode` must be passed by the caller.** This docblock used to say the classification was
     * "left to the model layer's own default, the same seam invoice lines use" — and there is no such
     * seam here: `InvoiceItem` defaults `tax_code` from its own `type` through the charge-code
     * catalogue, and a credit-note line HAD no `type` column to derive from (it does since SW-216 —
     * see below — so the seam is now available and simply not yet taken). So every system-raised
     * note was posting an unclassified line, and `CreditNoteJournalizer` — which since 2026-08-24
     * reverses tax at the SUPPLY'S OWN posting role — would fall back to the VAT floor for all of
     * them. The caller knows what it is crediting; it passes that down.
     *
     * **`$type` is the CHARGE CODE the credit relieves**, added 2026-09-03 for the same reason and
     * on the same terms (SW-216). A credit note could be matched to an invoice but never to a
     * charge, so `SyncCamPoolFromLedgerService` — which sums the service-charge lines a pool already
     * billed its participants — was gross of every credit note, and a PARTIAL credit moves no
     * invoice status, so no status filter could ever have seen one. Null means *not stated*: not
     * netted, rather than guessed.
     */
    public function describeAs(string $description, float $amount, float $vatRate, float $vatAmount, ?string $taxCode = null, ?string $type = null): CreditNoteItem
    {
        return $this->items()->create([
            'type' => $type,
            'description' => $description,
            'tax_code' => $taxCode,
            'amount' => round($amount, 2),
            'vat_rate' => $vatRate,
            'vat_amount' => round($vatAmount, 2),
            'total' => round($amount + $vatAmount, 2),
        ]);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CreditNoteApplication::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /**
     * Statuses whose supply is NOT on the books, with the reason for each.
     *
     * A reason per row rather than a bare list, for the reason `InvoiceSettlement` and
     * `DeletionPolicy` give: whoever adds the next status has to say which side it falls on.
     *
     * **This existed as the literal `['draft', 'cancelled']` in the VAT return, and
     * `credit_notes.status` has no `cancelled`** — the set is `draft | issued | applied | void`
     * (`ValueSets`). So the filter excluded a status that cannot occur and counted every voided
     * note.
     *
     * **Do NOT use this constant to decide whether a note belongs on a PERIOD report.** Voiding
     * does not erase the journal entry: `JournalPostingService::void()` posts a sign-flipped
     * reversal and marks the original `void`, and `void` is one of
     * `JournalEntry::REPORTABLE_STATUSES`, so the original's lines are still summed in their own
     * period. Whether the ledger is net of a void therefore depends on WHERE the reversal landed —
     * the original's period if it was still open, today's if it had closed — which is a question
     * about dates that no status can answer. `VatReturnService` asks it properly; this constant
     * answers the narrower, timeless question of whether a note is a document at all.
     */
    public const NOT_ON_THE_BOOKS = [
        'draft' => 'Never issued. Nothing was posted and the tenant has never seen it, so no supply was reduced.',
        'void' => 'Reversed. A note may be voided straight from DRAFT — `EditCreditNote` permits it — so a void note has not necessarily ever been posted, and where it was, the reversal may sit in a later period than the note. Neither makes it a live document.',
    ];

    /**
     * The notes a return, a tie-out or a reduction must count.
     *
     * DERIVED by exclusion, never allowlisted: a status this class has not heard of counts, and has
     * to be excluded deliberately. Dropping a real supply off a filed return is the worse failure
     * and the silent one — the same reasoning `TenantVisibility` gives for excluding rather than
     * allowlisting.
     */
    public function scopeOnTheBooks(Builder $query): Builder
    {
        return $query->whereNotIn('status', array_keys(self::NOT_ON_THE_BOOKS));
    }

    /** The row-level twin of {@see scopeOnTheBooks()}. */
    public function isOnTheBooks(): bool
    {
        return ! array_key_exists((string) $this->status, self::NOT_ON_THE_BOOKS);
    }

    /**
     * Money still available to apply — and the third place this judgement used to be re-listed.
     *
     * `in_array($this->status, ['issued', 'applied'])` is the complement of
     * {@see NOT_ON_THE_BOOKS} by coincidence, not by derivation, so a fifth status would have been
     * spendable here while the GL skipped it. Asked of the registry now, like everything else.
     */
    public function hasBalance(): bool
    {
        return (float) $this->balance > 0 && $this->isOnTheBooks();
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

        return sprintf('%s-%s-%s', DocumentNumbering::prefixFor('credit_note'), $assetCode, DocumentNumbering::periodSegment($issueDate));
    }

    public static function generateNumber(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $prefix = static::numberPrefix($assetCode, $issueDate);

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            // LENGTH first: `orderByDesc('number')` alone is a STRING sort, so once a series passes
            // its zero-padding the shorter number sorts higher and MAX returns the wrong row.
            ->orderByRaw('LENGTH(number) DESC, number DESC')
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
            // ── A STATUS IS THE OUTCOME OF AN ACT, NOT SOMETHING YOU PICK ────────────────────────
            //
            // Every non-draft status here is derived or act-driven, and each act carries its own
            // permission: `issued` comes from the Issue button (`credit_notes.issue`), `applied` is
            // derived from the balance by `applyToInvoice()`, and `void` comes from the Void button
            // (`credit_notes.void`). The form's status Select needed only `credit_notes.edit`, so it
            // was a way to produce all three effects with none of their checks. Measured, the two
            // permissions are held by the same four roles today, so this is defence in depth for a
            // hand-built role rather than a live escalation — the substantive holes are the skipped
            // CHECKS below, which no role assignment protects against.
            //
            // What the acts do and the Select skipped:
            //   ISSUE   `PostingDate::assertOpen()` on the issue date. Without it the AR effect
            //           commits while the journal post is silently refused inside the best-effort
            //           sync — the operator sees "Saved" and the ledger never moves.
            //   VOID    refuses a note with applied credit (it would strand the application), zeroes
            //           the balance, stamps `voided_at`, records the WHY in the audit trail and lets
            //           the poster reverse the entry.
            //
            // The MONEY rules are enforced here on every path — the services satisfy them already,
            // so nothing legitimate is refused — and `voided_at` tells an act from a picked status
            // AT THE WRITE, because the acts stamp it and no form offers it. It does NOT keep
            // discriminating over a record's life: a stamp survives, which is why the terminal rule
            // above exists rather than relying on it.
            if ($note->isDirty('status')) {
                $from = (string) $note->getOriginal('status');
                $to = (string) $note->status;

                // **A VOID NOTE IS TERMINAL.** Its journal entry has been reversed, its balance
                // zeroed and its `voided_at` stamped; nothing legitimately brings it back —
                // `CreditNoteService::void()` returns early on an already-void note and no other
                // service moves a status off `void`. Left unguarded, an un-void was reachable and
                // silent: the note prints with no VOID watermark on the document the tenant files,
                // every list reads it as live, and `voided_at` stays stamped — so the blank-stamp
                // discriminator below is permanently satisfied for that note, and a later crafted
                // `status = void` walks past the guard written to stop it. The correction for a
                // note voided in error is a NEW note, as it is for every other money document.
                if ($from === 'void' && $to !== 'void') {
                    throw new \DomainException(__('admin.refusals.credit_note_void_is_terminal'));
                }

                if ($to === 'void' && $from !== 'void') {
                    if ((float) $note->getOriginal('applied_amount') > 0) {
                        throw new \DomainException(__('admin.notifications.credit_note_void_applied'));
                    }

                    if (blank($note->voided_at)) {
                        throw new \DomainException(__('admin.refusals.credit_note_status_is_an_act'));
                    }
                }

                // Leaving draft is the GL event. Asserted here as well as in the service so the
                // form and a crafted payload both meet it — `assertOpen` is idempotent, and a
                // MISSING period is allowed, only a CLOSED one refused. (`CreateCreditNote`
                // already asserted it on the create path; this is the edit path.)
                // Draft → on the books is the moment the note posts, so the period is checked
                // here. Asked of the register rather than re-listing its complement.
                if ($from === 'draft' && ! array_key_exists($to, self::NOT_ON_THE_BOOKS)) {
                    PostingDate::assertOpen($note->issue_date, __('admin.fields.issue_date'));
                }
            }

            if ($note->getOriginal('status') === 'draft') {
                return; // draft is freely editable (and draft→issued must be allowed)
            }
            if ($note->status === 'draft') {
                throw new \DomainException(__('admin.refusals.credit_note_no_return_to_draft'));
            }
            foreach (['issue_date', 'tenant_id', 'invoice_id'] as $field) {
                if ($note->isDirty($field)) {
                    throw new \DomainException(__('admin.refusals.immutable_credit_note', ['field' => Translate::orHumanized("admin.fields.{$field}", $field)]));
                }
            }
            // `asset_id` and `lease_id` may each be bound ONCE from null — a standalone note
            // adopting the property of the first invoice it settles (CreditNoteService::
            // applyToInvoice, so the sales-return posts to that property). Re-homing an
            // already-scoped note stays refused: `asset_id` IS the entry's property dimension, so
            // moving it books the reversal into another mall's P&L and another owner's statement.
            foreach (['asset_id', 'lease_id'] as $bindOnce) {
                if ($note->isDirty($bindOnce) && $note->getOriginal($bindOnce) !== null) {
                    throw new \DomainException(__('admin.refusals.immutable_credit_note', ['field' => Translate::orHumanized("admin.fields.{$bindOnce}", $bindOnce)]));
                }
            }

            // ── `applied_amount` is what the APPLICATION ROWS say, and nothing else ────────────
            // It was fillable and unguarded until 2026-08-23: `update(['applied_amount' => 0])`
            // stuck on a fully applied note, so a spent credit read unspent and could be applied
            // all over again — the same money given away twice. Found by the mutation audit of
            // `DerivedMoneyConformanceTest`, which generates its tampering cases from a registry
            // this column was missing from.
            //
            // Held to `Σ CreditNoteApplication.amount` rather than reverted to the ORIGINAL,
            // because the rows ARE the truth: they are what `reverseApplication()` un-applies from
            // and what the invoice side is reconciled against. Reverting to the previous value
            // would restore a figure that might itself have been wrong; the sum cannot be.
            //
            // The invoice-side twin (`Invoice::credit_applied_amount`) is reverted instead of
            // summed, because its legitimate writer persists through `saveQuietly()` and never
            // reaches its hook. This one's writer needs its events — a newly bound lease re-syncs
            // the note's GL entry — so it cannot be excluded that way, and all three write paths
            // in `CreditNoteService` now write the application row BEFORE saving the note so the
            // sum is already correct when this runs.
            if ($note->isDirty('applied_amount')) {
                $applied = round(
                    (float) CreditNoteApplication::query()
                        ->where('credit_note_id', $note->getKey())
                        ->sum('amount'),
                    2,
                );

                if (abs((float) $note->applied_amount - $applied) > 0.005) {
                    $note->applied_amount = $applied;
                    $note->balance = round((float) $note->total - $applied, 2);
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
                throw new \DomainException(__('admin.refusals.credit_note_still_applied'));
            }
        });
    }
}
