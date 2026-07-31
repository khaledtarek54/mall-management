<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A violation recorded against a tenant (module 31) — FR-REQ-15/16/17.
 *
 * Eltizam records that a tenant breached a mall rule (blocked fire exit,
 * unauthorised signage, after-hours noise, …) with an optional associated
 * cost/fine. Each violation stands in exactly one property (direct `asset_id`,
 * like Unit / Area / Equipment) and is raised against the SHARED Tenant (like
 * Invoice / Lease — the tenant may lease in several malls; the violation is
 * pinned to the mall where it happened).
 *
 * `fine_amount` RECORDS the money assessed (FR-REQ-15). It is billed to the tenant ONLY by an
 * explicit "Bill fine" action ({@see \App\Services\BillViolationFineService}) — never on create —
 * which issues a normal AR invoice (a VAT-exempt `violation_fine` line → misc_income) and stamps
 * `billed_invoice_id`. Recording a violation still touches no Invoice/GL; the money only enters the
 * books when the operator chooses to bill it. `notified_at` records when the operator sent the
 * tenant a notice (FR-REQ-17), also an explicit action ({@see \App\Services\SendViolationNoticeAction}).
 * The lifecycle is intentionally minimal: `open` → `resolved`.
 */
class Violation extends Model implements HasMedia
{
    use HasFactory, HasSearchText, InteractsWithMedia, LogsActivity, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_RESOLVED,
    ];

    /** Photographic evidence of the breach — PRIVATE (a tenant's violation record is confidential). */
    public const PHOTOS_COLLECTION = 'photos';

    /**
     * The operator's violation kinds. Strings, not a DB enum (project convention) — extend the set
     * without a migration. A field officer picks the kind instead of retyping it, and the operator
     * can then filter/report by it.
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'signage',
        'operating_hours',
        'cleanliness',
        'safety',
        'unauthorized_works',
        'noise',
        'other',
    ];

    protected $fillable = [
        'asset_id',
        'tenant_id',
        'category',
        'description',
        'fine_amount',
        'violation_date',
        'status',
        'notified_at',
        'notes',
        'created_by_user_id',
        'billed_invoice_id',
        'billed_at',
    ];

    protected $casts = [
        'fine_amount' => 'decimal:2',
        'violation_date' => 'date',
        'notified_at' => 'datetime',
        'billed_at' => 'datetime',
    ];

    /**
     * Status is NOT-NULL with a DB default; mirror it in the model so a create
     * that omits the field (or a blank Select) never sends null into the column.
     * `fine_amount` stays nullable by design (a violation may carry no fine).
     */
    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    /**
     * Category and description, plus the VIO- reference. That reference is an ACCESSOR
     * derived from the id, not a column, which is why it can only be folded after the insert —
     * see the `created` hook in HasSearchText. It is also the only identifier printed on the
     * notice handed to the tenant, so leaving it unsearchable would mean an operator holding the
     * notice could not find the record.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->category,
            $this->description,
            $this->reference,
        ];
    }

    public function registerMediaCollections(): void
    {
        // Never omit useDisk — medialibrary's default is fail-open ('public'); a violation photo
        // (which can show a tenant's premises) is confidential. MediaPrivacyConformanceTest gates it.
        $this->addMediaCollection(self::PHOTOS_COLLECTION)->useDisk('local');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'tenant_id', 'category', 'description', 'fine_amount', 'violation_date', 'status', 'notified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('violation');
    }

    /**
     * Human-friendly reference for the table + notices (no stored reference
     * column — the id is the natural key). Read-only, display-only.
     */
    public function getReferenceAttribute(): string
    {
        return 'VIO-'.str_pad((string) ($this->id ?? 0), 5, '0', STR_PAD_LEFT);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The fine invoice this violation produced (null until billed). */
    public function billedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'billed_invoice_id');
    }

    /** True once a tenant notice has been sent (FR-REQ-17). */
    public function isNotified(): bool
    {
        return $this->notified_at !== null;
    }

    /**
     * Billed = it produced a fine invoice that still posts revenue. ONLY a cancelled invoice frees
     * the fine to re-bill — cancelling is the one status that voids the GL entry; a credited invoice
     * keeps its AR + misc_income posting, so re-billing it would double-count (the exact asymmetry
     * fixed for utility recharge in module 10).
     */
    public function isBilled(): bool
    {
        $invoice = $this->billedInvoice;

        return $invoice instanceof Invoice && $invoice->status !== 'cancelled';
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    protected static function booted(): void
    {
        // A billed violation is the origin of a live AR invoice. Force-deleting it would hard-remove
        // the row and sever the audit link to that invoice (which survives, along with its GL entry).
        // Refuse it — cancel the fine invoice first (which frees isBilled). Soft-delete stays allowed:
        // it's reversible and keeps billed_invoice_id on the trashed row.
        static::forceDeleting(function (self $violation) {
            if ($violation->isBilled()) {
                return false;
            }
        });
    }
}
