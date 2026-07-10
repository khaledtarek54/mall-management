<?php

namespace App\Models;

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
    use HasFactory, LogsActivity, SoftDeletes;

    public const CATEGORIES = ['maintenance', 'utilities', 'cleaning_security', 'marketing', 'admin', 'other'];

    protected $fillable = [
        'number',
        'vendor_id',
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
        'balance' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'vendor_id', 'asset_id', 'category', 'total', 'paid_amount', 'balance'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor_bill');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
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

    /** Recognised on the GL once it's past draft (approved and beyond). */
    public function isPostable(): bool
    {
        return ! in_array($this->status, ['draft', 'cancelled'], true);
    }

    /**
     * Re-derive paid_amount / balance / status from the bill's payments. The
     * single source of truth for AP settlement — nothing else writes these.
     */
    public function recompute(): void
    {
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $this->paid_amount = $paid;

        if ($this->status === 'cancelled') {
            // A cancelled bill owes nothing — zero it HERE (the single source of
            // truth) so a later recompute can never resurrect a phantom payable.
            $this->balance = 0;
        } else {
            $this->balance = max(0, round((float) $this->total - $paid, 2));

            // Never override the manual draft state.
            if ($this->status !== 'draft') {
                $this->status = match (true) {
                    $this->balance <= 0 && $paid > 0 => 'paid',
                    $paid > 0 => 'partially_paid',
                    default => 'approved',
                };
            }
        }

        $this->saveQuietly();
    }

    public static function generateNumber(string $assetCode = 'GEN', ?\DateTimeInterface $billDate = null): string
    {
        $billDate = $billDate ? Carbon::instance($billDate) : now();
        $prefix = sprintf('BILL-%s-%s-', $assetCode, $billDate->format('Ym'));

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
        });

        static::creating(function (self $bill) {
            if (empty($bill->number)) {
                $bill->number = static::generateNumber($bill->asset?->code ?: 'GEN', $bill->bill_date);
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
        static::deleted(function (self $bill) {
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
