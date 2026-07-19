<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * شيك آجل — a post-dated cheque lodged by a tenant, tracked in a forward register with a maturity
 * date and a bounce lifecycle. v1 is register-only: the tenant's invoice stays open until the
 * cheque CLEARS, at which point a normal cheque Payment is recorded (so AR stays correct). A
 * cleared or cancelled cheque is terminal-immutable.
 */
class PostDatedCheque extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_HELD = 'held';           // received, awaiting maturity
    public const STATUS_DEPOSITED = 'deposited';  // presented to the bank
    public const STATUS_CLEARED = 'cleared';      // funds received → a Payment was recorded
    public const STATUS_BOUNCED = 'bounced';      // returned unpaid
    public const STATUS_CANCELLED = 'cancelled';  // voided before clearing
    public const STATUSES = [self::STATUS_HELD, self::STATUS_DEPOSITED, self::STATUS_CLEARED, self::STATUS_BOUNCED, self::STATUS_CANCELLED];

    protected $fillable = [
        'reference',
        'asset_id',
        'tenant_id',
        'lease_id',
        'invoice_id',
        'cheque_number',
        'bank_name',
        'amount',
        'currency',
        'cheque_date',
        'received_date',
        'status',
        'cleared_payment_id',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cheque_date' => 'date',
        'received_date' => 'date',
    ];

    protected $attributes = [
        'amount' => 0,
        'currency' => 'EGP',
        'status' => self::STATUS_HELD,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $cheque) {
            if (! in_array($cheque->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException("Invalid post-dated cheque status '{$cheque->status}'.");
            }
        });

        // A cleared/cancelled cheque is terminal — its money-material fields never change
        // (soft-delete/restore, which touch only deleted_at, are still allowed).
        static::updating(function (self $cheque) {
            $original = $cheque->getOriginal('status');
            if (in_array($original, [self::STATUS_CLEARED, self::STATUS_CANCELLED], true)
                && ($cheque->isDirty('status') || $cheque->isDirty('amount') || $cheque->isDirty('cleared_payment_id'))) {
                throw new \DomainException("A {$original} post-dated cheque is immutable.");
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'tenant_id', 'invoice_id', 'cheque_number', 'amount', 'cheque_date', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('post_dated_cheque');
    }

    public static function generateReference(): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;

        $candidate = sprintf('PDC-%s-%04d', $year, $count);
        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists() && $attempts < 50) {
            $candidate = sprintf('PDC-%s-%04d', $year, ++$count);
            $attempts++;
        }

        return $candidate;
    }

    /** Still outstanding (not yet cleared/cancelled) — the amount the register still expects to collect. */
    public function isOutstanding(): bool
    {
        return in_array($this->status, [self::STATUS_HELD, self::STATUS_DEPOSITED, self::STATUS_BOUNCED], true);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function clearedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'cleared_payment_id');
    }
}
