<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * فاتورة مورد — a vendor bill (Accounts Payable). Recognises an expense + a
 * payable; settled by VendorBillPayments. `paid_amount`/`balance` are DERIVED via
 * recompute() — never set directly (mirrors the Invoice AR invariant).
 */
class VendorBill extends Model
{
    use \App\Models\Concerns\AllocatesDocumentNumber;

    use HasFactory, LogsActivity, SoftDeletes;

    public const CATEGORIES = ['maintenance', 'utilities', 'cleaning_security', 'marketing', 'admin', 'other'];

    protected $fillable = [
        'purchase_request_id',
        'number',
        'vendor_id',
        'vendor_contract_id',
        'asset_id',
        'category',
        'status',
        'bill_date',
        'due_date',
        'reference',
        'description',
        'subtotal',
        'vat_amount',
        'total',
        'paid_amount',
        'penalty_applied_amount',
        'balance',
        'currency',
        'approved_by_user_id',
        'created_by_user_id',
        'approved_at',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'penalty_applied_amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'vendor_id', 'asset_id', 'category', 'total', 'paid_amount', 'penalty_applied_amount', 'balance'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor_bill');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The contract this bill was incurred under, if any.
     *
     * Optional by design — an ad-hoc call-out has no contract. When set it's what makes
     * `vendor_contracts.value` mean something: committed vs actually invoiced.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class, 'vendor_contract_id');
    }

    /**
     * The purchase this bill pays for, if any (FR-PROC-04's other half).
     *
     * When set, this bill is the goods half of a purchase whose receipt already debited
     * Inventory and credited GRNI — so it clears GRNI rather than charging the expense again.
     * See VendorBillJournalizer.
     */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorBillPayment::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * The child ledger sources whose GL follows this bill's lifecycle. A bill payment
     * (Dr AP / Cr Cash) only makes sense while the bill (Cr AP) stands — so a bill's
     * soft-delete / restore / re-home must flow to its payments, or the windowed
     * sync-ledger sweep (which keys on each row's own updated_at) would strand them.
     * Mirrors FixedAsset::ledgerChildRelations().
     */
    protected function ledgerChildRelations(): array
    {
        return [$this->payments()];
    }

    /**
     * Bump the OTHER postable bills on this bill's purchase request so the windowed
     * sync-ledger sweep re-derives their GRNI clearing.
     *
     * The clearing is FIFO across a request's bills (VendorBillJournalizer::goodsAwaitingInvoice):
     * each bill takes what the receipt credited minus what earlier bills already took. So adding,
     * re-dating, approving, cancelling, or deleting ONE bill changes what its SIBLINGS should
     * clear — but the sweep keys on each row's own `updated_at`, and a sibling whose own columns
     * didn't move would keep a now-stale clearing posted (GRNI stranded, or double-cleared, until
     * a CLI `accounting:sync-ledger --all`). Making "the FIFO set changed" a first-class re-derive
     * trigger closes it (audit M29 F-1). The mass update fires no model events → no recursion, and
     * a re-derive that finds no change is a sync no-op, so over-touching is harmless.
     */
    protected function touchPurchaseRequestSiblings(): void
    {
        if ($this->purchase_request_id === null) {
            return;
        }

        static::withTrashed()
            ->where('purchase_request_id', $this->purchase_request_id)
            ->whereKeyNot($this->getKey())
            ->update(['updated_at' => now()]);
    }

    /** The statuses that have no GL effect — the single definition {@see isPostable} and {@see scopePostable} share. */
    public const NON_POSTABLE_STATUSES = ['draft', 'cancelled'];

    /** Recognised on the GL once it's past draft (approved and beyond). */
    public function isPostable(): bool
    {
        return ! in_array($this->status, self::NON_POSTABLE_STATUSES, true);
    }

    /**
     * The query-side twin of {@see isPostable}, off the same constant so the two cannot drift.
     * Used by VendorBillJournalizer to share a purchase's received value across its bills — a
     * draft or cancelled bill must not consume any of it.
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::NON_POSTABLE_STATUSES);
    }

    /** FR-CM-08 — SLA penalties charged against this bill. */
    public function penalties(): HasMany
    {
        return $this->hasMany(MaintenancePenalty::class, 'vendor_bill_id');
    }

    /**
     * Re-derive paid_amount / penalty_applied_amount / balance / status from the bill's
     * payments and applied penalties. The
     * single source of truth for AP settlement — nothing else writes these.
     */
    public function recompute(): void
    {
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $this->paid_amount = $paid;

        // FR-CM-08 — an SLA penalty reduces what is payable, exactly as a credit note does
        // on the tenant side. It cannot be a line on the bill (there are none), so it is a
        // second derived offset, recomputed here rather than written anywhere else.
        // Only `applied` counts: `final` is assessed-and-owed, not yet deducted.
        $penalties = round((float) $this->penalties()->where('status', 'applied')->sum('amount'), 2);
        $this->penalty_applied_amount = $penalties;

        if ($this->status === 'cancelled') {
            // A cancelled bill owes nothing — zero it HERE (the single source of
            // truth) so a later recompute can never resurrect a phantom payable.
            $this->balance = 0;
        } else {
            $this->balance = max(0, round((float) $this->total - $paid - $penalties, 2));

            // Never override the manual draft state.
            if ($this->status !== 'draft') {
                $this->status = match (true) {
                    // A penalty can settle a bill on its own — the payable is extinguished
                    // even though no cash moved, so `paid` (nothing further owed) is the
                    // honest state. `$paid > 0` alone would leave it stuck on `approved`
                    // with a zero balance.
                    $this->balance <= 0 && ($paid > 0 || $penalties > 0) => 'paid',
                    $paid > 0 => 'partially_paid',
                    default => 'approved',
                };
            }
        }

        $this->saveQuietly();
    }

    /**
     * The number prefix for this document's sequence — ONE definition, used by generateNumber()
     * and by the allocation lock key (see AllocatesDocumentNumber). Two copies would drift, and a
     * lock keyed on a prefix that no longer matches the sequence it guards protects nothing.
     */
    public static function numberPrefix(string $assetCode = 'GEN', ?\DateTimeInterface $billDate = null): string
    {
        $billDate = $billDate ? Carbon::instance($billDate) : now();

        return sprintf('BILL-%s-%s-', $assetCode, $billDate->format('Ym'));
    }

    public static function generateNumber(string $assetCode = 'GEN', ?\DateTimeInterface $billDate = null): string
    {
        $prefix = static::numberPrefix($assetCode, $billDate);

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::saving(function (self $bill) {
            // Coerce blank NOT-NULL money inputs to 0. Read the RAW attribute — a
            // decimal:2 cast throws MathException if you read '' through the getter,
            // so `$bill->subtotal === ''` would crash the very import/API path this
            // guards (the meter_readings.cost bug class).
            foreach (['subtotal', 'vat_amount'] as $column) {
                $raw = $bill->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $bill->{$column} = 0;
                }
            }

            // Total is derived (subtotal + VAT), enforced on EVERY write path — not
            // just the form. Prevents a programmatic create from persisting total=0
            // (which the journalizer would silently skip) or vat>total (negative
            // expense). The single source of truth for the bill total.
            $bill->total = round((float) $bill->subtotal + (float) $bill->vat_amount, 2);

            // A bill that pays for a purchase request must post its GRNI clearing in the SAME
            // property the receipt credited GRNI in (the request's warehouse). The receipt
            // debited Inventory / credited GRNI in the REQUEST's property; this bill's clearing
            // debit posts to the BILL's asset_id (VendorBillJournalizer). If they differ, GRNI
            // stays uncleared in one mall and swings positive in another — permanently, because
            // nothing re-derives a "correct" cross-property link and the close gate is blind to
            // it (the entry matches its own payload). The form scopes the PR picker; this is the
            // money gate below the form (audit M29 F-2).
            if ($bill->purchase_request_id !== null && ($bill->isDirty('purchase_request_id') || $bill->isDirty('asset_id'))) {
                $prAssetId = PurchaseRequest::whereKey($bill->purchase_request_id)->value('asset_id');
                if ($prAssetId !== null && (int) $prAssetId !== (int) $bill->asset_id) {
                    throw new \DomainException('The linked purchase request belongs to a different property than the bill.');
                }
            }
        });

        // A bill past draft is FINALIZED — it posts (Dr Expense/Cr AP) to the GL. Its money-material
        // and counterparty fields are then immutable: the AP mirror of Invoice's updating guard,
        // closing the JS-tamper / API / console path BEHIND the form's disabled() lock. Off-form,
        // editing a paid/cancelled bill's subtotal re-derives the GL at the inflated total via the
        // sweep while the payment stays applied → overstated expense/AP + a phantom re-opened balance
        // on a "paid" bill. The remedy for a wrong posted bill is to cancel + re-enter, never edit it.
        // Derived fields (paid_amount/balance/penalty via recompute()'s saveQuietly) don't fire this,
        // and status progression (draft→approved→paid, cancel) doesn't dirty the frozen set. Pre-go-live
        // sweep (terminal-state) — VendorBill was the one money model missing the peer AR/lease guard.
        static::updating(function (self $bill) {
            if ($bill->getOriginal('status') === 'draft') {
                return; // draft is freely editable, including draft→approved
            }
            foreach (['subtotal', 'vat_amount', 'vendor_id', 'category', 'purchase_request_id'] as $field) {
                if ($bill->isDirty($field)) {
                    throw new \DomainException('A finalized vendor bill is immutable — cancel and re-enter it instead of editing its amount, vendor, category, or purchase link.');
                }
            }
        });

        static::creating(function (self $bill) {
            if (empty($bill->number)) {
                // Resolved once: the prefix that keys the lock and the sequence the
                // generator reads must be the same string, or the lock guards nothing.
                $assetCode = $bill->asset?->code ?: 'GEN';

                $bill->number = $bill->allocateDocumentNumber(
                    static::numberPrefix($assetCode, $bill->bill_date),
                    fn (): string => static::generateNumber($assetCode, $bill->bill_date),
                );
            }
            if (empty($bill->currency)) {
                $bill->currency = 'EGP';
            }
            if ($bill->balance === null) {
                $bill->balance = (float) ($bill->total ?? 0) - (float) ($bill->paid_amount ?? 0);
            }
        });

        // Keep the payments' ledger entries in lock-step with the bill (mirrors
        // FixedAsset). Payments are their OWN ledger sources discovered by the windowed
        // sync-ledger sweep via their own updated_at, so a change to the PARENT bill
        // never reaches them without these hooks. (GL integrity hardening — Phase 0.)

        // Soft-delete cascades to the payments, stamped with the bill's OWN deleted_at
        // so a later restore targets exactly the rows THIS delete trashed. Without it,
        // deleting a paid bill voids the bill entry (Cr AP) but leaves each payment's
        // Dr AP / Cr Cash posted — an unbalanced, understated AP/cash until --all (F9/High).
        // A force-delete lets the FK cascade physically remove the payments instead.
        // A new/changed bill re-derives its purchase-request siblings' GRNI FIFO (audit M29 F-1).
        // Only the fields that move the FIFO — membership, order, and each bill's net — matter;
        // recompute()'s paid_amount/status churn is saveQuietly (no event) and irrelevant here.
        static::saved(function (self $bill) {
            if ($bill->wasRecentlyCreated
                || $bill->wasChanged(['status', 'bill_date', 'total', 'vat_amount', 'purchase_request_id'])) {
                $bill->touchPurchaseRequestSiblings();
            }
        });

        static::deleted(function (self $bill) {
            // Deleting a bill (soft OR force) shrinks the purchase-request FIFO set, so its
            // siblings must re-derive their clearing (audit M29 F-1) — before the force-delete
            // early-return below, since the set shrank either way.
            $bill->touchPurchaseRequestSiblings();

            if ($bill->isForceDeleting()) {
                return;
            }
            foreach ($bill->ledgerChildRelations() as $relation) {
                $relation->update(['deleted_at' => $bill->deleted_at, 'updated_at' => now()]);
            }
        });

        // Restore ONLY the payments this bill's delete cascaded (matched on that exact
        // deleted_at) so a payment removed for another reason stays removed.
        static::restoring(function (self $bill) {
            foreach ($bill->ledgerChildRelations() as $relation) {
                $relation->onlyTrashed()
                    ->where('deleted_at', $bill->deleted_at)
                    ->update(['deleted_at' => null, 'updated_at' => now()]);
            }
        });

        // A restored bill re-enters the purchase-request FIFO set → its siblings re-derive
        // (audit M29 F-1). deleted_at is already null here, so touchPurchaseRequestSiblings'
        // withTrashed()->whereKeyNot bumps exactly the siblings.
        static::restored(function (self $bill) {
            $bill->touchPurchaseRequestSiblings();
        });

        // Re-home: the bill's asset_id is the books dimension of its payments' GL
        // (VendorBillPaymentJournalizer reads bill->asset_id). Bump the payments so the
        // windowed sweep re-derives their dimension rather than stranding it (F9).
        static::updated(function (self $bill) {
            if ($bill->wasChanged('asset_id')) {
                foreach ($bill->ledgerChildRelations() as $relation) {
                    $relation->withTrashed()->update(['updated_at' => now()]);
                }
            }
        });
    }
}
