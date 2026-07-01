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
        // NOT-NULL money columns: coerce a blank/cleared field to 0 so a save that
        // bypasses the form (service / API / import) never violates the constraint
        // (the meter_readings.cost bug class).
        static::saving(function (self $bill) {
            foreach (['subtotal', 'vat_amount', 'total'] as $column) {
                if ($bill->{$column} === null || $bill->{$column} === '') {
                    $bill->{$column} = 0;
                }
            }
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
    }
}
