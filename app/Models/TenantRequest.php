<?php

namespace App\Models;

use App\Enums\TenantRequestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            ->logOnly(['request_type', 'status', 'priority', 'category', 'assigned_to', 'assigned_to_vendor_id', 'department_id', 'target_resolution_at', 'resolution_notes', 'csat_rating'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('maintenance_request');
    }

    protected $fillable = [
        'reference',
        'tenant_id',
        'unit_id',
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
        'sla_breach_notified_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
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

        return sprintf('%s-%s-%s-%04d', $prefix, $assetCode, $year, $count);
    }
}
