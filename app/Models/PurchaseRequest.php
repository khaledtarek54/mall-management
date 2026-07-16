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
 * A procurement request (module 29, FR-PROC-01..05) — "we need this, here's why".
 *
 * Property-owned: a mall's storeroom needs the parts and its budget pays for them.
 *
 * FR-PROC-05 wants a status history (Requested → Approved → Ordered → Received). That is the
 * activity log's job, not a bespoke table: `logOnly([...'status'...])` records who/when/from→to for
 * free, and spatie v5 stores the before/after in `attribute_changes`. A dedicated history table
 * would only be needed if per-step comments or attachments were required — the FRD asks for
 * neither (confirm before building one).
 */
class PurchaseRequest extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED,
        self::STATUS_ORDERED, self::STATUS_RECEIVED, self::STATUS_CANCELLED,
    ];

    /**
     * FR-PROC-05's ladder, as a matrix (mirrors MaintenanceWorkOrderService::TRANSITIONS).
     *
     * **`requested` cannot jump to `ordered`.** That single omission is FR-PROC-02 — "route
     * procurement requests through an approval workflow **before order placement**" — expressed as
     * data rather than as a hopeful comment. Ordering an unapproved request is not a shortcut, it
     * is the failure the module exists to prevent.
     */
    public const TRANSITIONS = [
        self::STATUS_REQUESTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_ORDERED, self::STATUS_CANCELLED],
        self::STATUS_ORDERED => [self::STATUS_RECEIVED, self::STATUS_CANCELLED],
        self::STATUS_RECEIVED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    public const TERMINAL = [self::STATUS_RECEIVED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    protected $fillable = [
        'asset_id', 'reference', 'status', 'justification', 'warehouse_id', 'vendor_id',
        'total_value', 'required_permission', 'requested_by_user_id', 'decided_by_user_id',
        'decided_at', 'decision_notes', 'ordered_by_user_id', 'ordered_at', 'order_reference',
        'received_by_user_id', 'received_at',
    ];

    protected $casts = [
        'total_value' => 'decimal:2',
        'decided_at' => 'datetime',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_REQUESTED,
        'total_value' => 0,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        // FR-PROC-05: `status` is the point — this IS the status history.
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'status', 'justification', 'warehouse_id', 'vendor_id',
                'total_value', 'required_permission', 'decision_notes', 'order_reference'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('purchase_request');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class);
    }

    /**
     * The supplier invoices billed against this purchase. Usually one, but a split delivery or a
     * deposit-plus-balance legitimately produces several — which is exactly why
     * VendorBillJournalizer shares the received value across them FIFO rather than letting each
     * one clear the full amount (gap-analysis F-101).
     */
    public function bills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL);
    }

    /** Lines that become stock on receipt (FR-PROC-04). A service line is not stock. */
    public function stockableLines(): HasMany
    {
        return $this->lines()->whereNotNull('inventory_item_id');
    }

    /** Recompute from the lines. The value the approval tier is judged on must never drift. */
    public function recomputeTotal(): void
    {
        $this->total_value = round((float) $this->lines()->sum('line_value'), 2);
        $this->saveQuietly();
    }

    /** `PR-{asset}-{YYYYMM}-{n}` — mirrors the work order's reference scheme. */
    public static function generateReference(string $assetCode = 'GEN', ?\DateTimeInterface $date = null): string
    {
        $date = $date ? Carbon::instance($date) : now();
        $prefix = sprintf('PR-%s-%s-', $assetCode, $date->format('Ym'));

        $last = static::withTrashed()->where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        // Bump until free — max+1 races under concurrent creates; the unique index is the backstop.
        $candidate = $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            $candidate = $prefix.str_pad((string) ++$next, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            if (empty($request->reference)) {
                $request->reference = static::generateReference($request->asset?->code ?: 'GEN');
            }
        });
    }
}
