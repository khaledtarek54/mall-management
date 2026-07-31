<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\ApprovalPolicy;
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
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

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
        'po_number', 'received_by_user_id', 'received_at',
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

    /**
     * The request reference, plus the order and PO numbers it turns into downstream.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->order_reference,
            $this->po_number,
        ];
    }

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

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * withTrashed for the same reason `StockMovement::warehouse()` and `Custody`/
     * `PayrollLine::employee()` do it: `Warehouse` soft-deletes, so the FK's `nullOnDelete`
     * never fires and `warehouse_id` keeps pointing at the archived row. Without this the
     * `belongsTo` resolved to NULL and slipped past `receive()`'s `warehouse_id === null`
     * guard — then `(int) null->asset_id` = 0 ≠ asset_id, so it landed in the CROSS-PROPERTY
     * branch and told the operator the warehouse "belongs to another property". Isolation still
     * held (it refused), but the diagnosis was false and the request was stuck in `ordered`
     * forever, because `warehouse_id` is only editable while `requested`. Under HTTP the same
     * null-deref is an ErrorException, which the table (catching DomainException only) would
     * not handle — a 500 (gap-analysis F-105).
     *
     * Retiring a storeroom while its order is in transit is ordinary; the goods still have to
     * land somewhere, and the request must still say where they were going.
     */
    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<PurchaseRequestLine, $this> */
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

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by_user_id');
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

    /**
     * Recompute from the lines. The value the approval tier is judged on must never drift.
     *
     * **`required_permission` follows the total**, while the request is still open to change.
     * It is the frozen answer to "who was SUPPOSED to approve this" — the record's own audit of
     * the policy in force when it was raised, kept so a later edit to the bands cannot rewrite
     * history. But it was only ever written by `PurchaseRequestService::request()`, and the
     * Filament create page doesn't call it: the form collects no lines (they are added afterwards
     * through the relation manager), so the header is created first and the service's
     * header-plus-lines shape never fitted the UI. Net effect: **NULL on 100% of production
     * rows** — `pr_pending_queue_index` on `(status, required_permission)` indexed a permanently
     * null column, and the list rendered its `unknown` fallback ("Needs a higher authority") on
     * every request, including a 500 EGP one needing only a supervisor (gap-analysis F-104).
     *
     * Derived HERE, not on the create page, because this is where the total is derived and the
     * two answer the same question. It stops the moment the request leaves `requested`: after a
     * decision the tier is history, and `approve()` judges the CURRENT total anyway — which is
     * the guard that keeps "approved at 500, quietly becomes 50,000" from working.
     */
    public function recomputeTotal(): void
    {
        $this->total_value = round((float) $this->lines()->sum('line_value'), 2);

        if ($this->status === self::STATUS_REQUESTED) {
            $this->required_permission = ApprovalPolicy::permissionFor(
                ApprovalRule::MODULE_PURCHASE_REQUEST,
                (float) $this->total_value,
            );
        }

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

    /** `PO-{asset}-{YYYYMM}-{n}` — the PURCHASE ORDER's own number, distinct from the PR reference. */
    public static function generatePoNumber(string $assetCode = 'GEN', ?\DateTimeInterface $date = null): string
    {
        $date = $date ? Carbon::instance($date) : now();
        $prefix = sprintf('PO-%s-%s-', $assetCode, $date->format('Ym'));

        $last = static::withTrashed()->where('po_number', 'like', $prefix.'%')->orderByDesc('po_number')->value('po_number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        // Bump until free — max+1 races under concurrent orders; the unique index is the backstop.
        $candidate = $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        while (static::withTrashed()->where('po_number', $candidate)->exists()) {
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

        static::saving(function (self $request) {
            // The warehouse the goods land in must belong to the SAME property that requested
            // (and pays for) them. The form scopes the picker to the request's asset; this is the
            // gate below it. Without it a crafted submit pins another mall's warehouse: receive()
            // refuses to LAND stock there (its own backstop), but the foreign warehouse_id
            // persists and its name surfaces on the PO PDF — a name/existence oracle for an
            // invisible property's storerooms. Checked on create + on any change (audit M29-2).
            if ($request->warehouse_id !== null && ($request->isDirty('warehouse_id') || $request->isDirty('asset_id'))) {
                $warehouseAssetId = Warehouse::whereKey($request->warehouse_id)->value('asset_id');
                if ($warehouseAssetId !== null && (int) $warehouseAssetId !== (int) $request->asset_id) {
                    throw new \DomainException('The selected warehouse belongs to a different property than the request.');
                }
            }
        });

        static::updating(function (self $request) {
            // The header freezes wholesale once the request leaves `requested`: asset_id (the
            // budget), warehouse_id (where the goods land) and justification (what the approval
            // signed off on) are frozen the moment it is approved. Changing any afterwards strands
            // the PO/receipt from what was authorised — a received request's warehouse_id could
            // diverge from the actual stock movement, and a re-downloaded PO would name a storeroom
            // that never received the goods. The status transition itself is allowed because
            // getOriginal('status') is still `requested` when approve() flips it. Mirrors
            // PostDatedCheque's terminal immutability (audit M29-1).
            if ($request->getOriginal('status') !== self::STATUS_REQUESTED) {
                foreach (['asset_id', 'warehouse_id', 'justification'] as $frozen) {
                    if ($request->isDirty($frozen)) {
                        throw new \DomainException('A purchase request cannot change its property, warehouse, or justification after it has been approved.');
                    }
                }
            }
        });
    }
}
