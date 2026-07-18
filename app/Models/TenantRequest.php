<?php

namespace App\Models;

use App\Enums\TenantRequestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class TenantRequest extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    public const STATUSES = [
        'submitted',
        'acknowledged',
        'in_progress',
        'awaiting_tenant',
        'resolved',
        'closed',
        'cancelled',
    ];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public const CATEGORIES = [
        'electrical',
        'plumbing',
        'hvac',
        'structural',
        'cleaning',
        'safety',
        'other',
    ];

    public const OPEN_STATUSES = ['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['request_type', 'status', 'priority', 'category', 'assigned_to', 'assigned_to_vendor_id', 'department_id', 'area_id', 'target_resolution_at', 'valid_from', 'valid_to', 'resolution_notes', 'csat_rating'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('maintenance_request');
    }

    protected $fillable = [
        'reference',
        'tenant_id',
        'unit_id',
        'area_id',
        'caller_name',
        'caller_phone',
        'caller_notes',
        'lease_id',
        'request_type',
        'assigned_to',
        'assigned_to_vendor_id',
        'department_id',
        'status',
        'priority',
        'category',
        'channel',
        'title',
        'description',
        'resolution_notes',
        'submitted_at',
        'acknowledged_at',
        'resolved_at',
        'closed_at',
        'target_resolution_at',
        'scheduled_from',
        'scheduled_to',
        'valid_from',
        'valid_to',
        'sla_breach_notified_at',
        'csat_rating',
        'csat_comment',
    ];

    protected $casts = [
        'request_type' => TenantRequestType::class,
        'csat_rating' => 'integer',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'target_resolution_at' => 'datetime',
        'scheduled_from' => 'datetime',
        'scheduled_to' => 'datetime',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'sla_breach_notified_at' => 'datetime',
    ];

    /** The only channel a tenant raises for themselves; every other channel is staff-logged intake. */
    public const SELF_SERVICE_CHANNEL = 'portal';

    protected static function booted(): void
    {
        // FR-REQ intake invariant (Phase 9a), enforced in the model so admin + portal + API all
        // inherit it. A request may omit its tenant ONLY when a staff channel logged it for an
        // unregistered caller — and even then it must record WHO reported it. A tenant-less portal
        // request is a contradiction (the portal always acts as a known, authenticated tenant).
        static::saving(function (self $request) {
            if ($request->tenant_id !== null) {
                return;
            }

            if ($request->channel === self::SELF_SERVICE_CHANNEL) {
                throw new \DomainException(__('admin.maintenance.errors.portal_needs_tenant'));
            }

            if (blank($request->caller_name)) {
                throw new \DomainException(__('admin.maintenance.errors.caller_or_tenant_required'));
            }
        });

        // FR-REQ-14 permit validity window. A permit carries a validity period (valid_from/to);
        // enforced in the model so admin + portal + API all inherit it. Only the ORDERING is an
        // invariant here — if BOTH dates are set, valid_to must not predate valid_from. The dates
        // are NOT hard-required at this layer (the permit form requires them for its type), so
        // non-permit rows and partial data never blow up. There is NO approval step.
        static::saving(function (self $request) {
            if ($request->valid_from === null || $request->valid_to === null) {
                return;
            }

            if ($request->valid_to->lt($request->valid_from)) {
                throw new \DomainException(__('admin.maintenance.errors.permit_validity_order'));
            }
        });

        // Area routing (module 30 → 11), derived in the model so admin + portal + API all inherit
        // it. A request lives in its unit's facility zone: if no area was set explicitly, inherit
        // the unit's. An explicitly-set area_id is never overridden (a caller may target a zone
        // directly). Kept cheap — a single-column lookup, only when area_id is null. (unit_id is
        // NOT NULL, so the lookup always has a key; a unit with no zone just yields null.)
        static::creating(function (self $request) {
            if ($request->area_id !== null) {
                return;
            }

            $request->area_id = Unit::whereKey($request->unit_id)->value('area_id');
        });

        // Notify the zone's supervisors that a request landed in their area (routing, not
        // assignment). Fires from the model `created` event — the single hook every create path
        // (admin Filament, portal, mobile API) passes through — so no channel can skip it. A no-op
        // when there's no area or no supervisors; failures are contained inside the service.
        static::created(function (self $request) {
            app(\App\Services\NotifyAreaSupervisorsService::class)->notify($request);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Who reported it: the registered tenant's name, or — for a caller-only intake — the caller. */
    public function reportedByName(): ?string
    {
        // Key off tenant_id: the FK guarantees a tenant row whenever it is set; a caller-only row
        // (tenant_id null) reads the caller name instead.
        if ($this->tenant_id === null) {
            return $this->caller_name;
        }

        /** @var Tenant $tenant */
        $tenant = $this->tenant;

        return $tenant->name;
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The unit's code, null-safe — for notification copy (Larastan mistypes the BelongsTo). */
    public function unitCode(): ?string
    {
        /** @var Unit|null $unit */
        $unit = $this->unit;

        return $unit?->code;
    }

    /** The facility zone this request sits in (module 30) — inherited from the unit on intake. */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** The zone's display name, null-safe — for the table/notification copy. */
    public function areaName(): ?string
    {
        // Key off the FK: a set area_id guarantees a row, but the zone may be soft-deleted (the
        // default relation excludes trashed), so the local $area can still be null.
        if ($this->area_id === null) {
            return null;
        }

        /** @var Area|null $area */
        $area = $this->area;

        return $area?->name;
    }

    /**
     * FR-USR-06 — the facility work orders raised to fix this request (module 26).
     *
     * A request can spawn more than one (a flood needs plumbing AND electrical). The existence of
     * any one is the "linked work order" that satisfies FR-USR-06's completion evidence.
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'tenant_request_id');
    }

    /** Is there facility work on record for this request? (FR-USR-06 evidence.) */
    public function hasLinkedWorkOrder(): bool
    {
        return $this->workOrders()->exists();
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'assigned_to_vendor_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TenantRequestComment::class)->orderBy('created_at');
    }

    /** Stock consumed against this request (inventory module 22, Phase 2). */
    public function stockConsumptions(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source')
            ->where('type', 'consumption')
            ->latest('moved_on');
    }

    /**
     * Attachments live on a PRIVATE disk (not web-accessible). They're tenant
     * photos/documents and must never be reachable via a guessable public URL —
     * they're streamed only through authenticated, tenant-scoped endpoints (the
     * mobile API controller + the authed admin panel). See hardening backlog H2.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->useDisk('local');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Terminal (closed/cancelled) work-orders are immutable — FR REQ-3.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['closed', 'cancelled'], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->target_resolution_at
            && $this->target_resolution_at->isPast();
    }

    /**
     * Localised label for this request's type (Maintenance, Complaint, …). Used
     * as the `:type` placeholder in notification copy so a complaint never reads
     * "Maintenance …". Falls back to the maintenance label for legacy rows.
     */
    public function typeLabel(): string
    {
        return ($this->request_type ?? TenantRequestType::default())->label();
    }

    public static function generateReference(string $assetCode = 'AW', string $prefix = 'MR'): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;

        // Bump the suffix until the reference is free — the bare count is race-prone
        // under concurrent creates (mirrors the Invoice/Payment number helpers).
        $candidate = sprintf('%s-%s-%s-%04d', $prefix, $assetCode, $year, $count);
        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists() && $attempts < 50) {
            $candidate = sprintf('%s-%s-%s-%04d', $prefix, $assetCode, $year, ++$count);
            $attempts++;
        }

        return $candidate;
    }
}
