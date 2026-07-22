<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Vendor extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BLACKLISTED = 'blacklisted';

    /** Insurance / COI documents — PRIVATE (a vendor's cert is confidential); on the shared vendor. */
    public const COI_COLLECTION = 'coi';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'status', 'email', 'phone', 'tax_id', 'coi_expires_at', 'insurer', 'policy_number'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor');
    }

    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'legal_name',
        'tax_id',
        'coi_expires_at',
        'insurer',
        'policy_number',
        'coi_alert_stage',
        'coi_alert_for',
        'email',
        'phone',
        'address',
        'city',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'coi_expires_at' => 'date',
        'coi_alert_for' => 'date',
    ];

    /** Days before a COI lapses that the operator starts being chased to renew it. */
    public const COI_ALERT_DAYS = 30;

    public const COI_STAGE_EXPIRING = 'expiring';
    public const COI_STAGE_EXPIRED = 'expired';

    public function registerMediaCollections(): void
    {
        // Never omit useDisk — medialibrary's default is fail-open ('public'); a COI is private.
        $this->addMediaCollection(self::COI_COLLECTION)->useDisk('local');
    }

    // ============ Compliance / COI gate ============

    public function isBlacklisted(): bool
    {
        return $this->status === self::STATUS_BLACKLISTED;
    }

    /** True only when a COI date is on file AND it is in the past — a null (not-recorded) COI is not "expired". */
    public function hasCoiExpired(?Carbon $on = null): bool
    {
        $on = ($on ?? Carbon::today())->startOfDay();

        return $this->coi_expires_at !== null && $this->coi_expires_at->startOfDay()->lt($on);
    }

    /**
     * May this vendor be dispatched to work? Active status (so blacklisted/inactive are out) AND a
     * COI that isn't lapsed. A vendor with no COI recorded is still assignable (v1 doesn't force a
     * cert on every existing vendor) — blacklist one to hard-block it.
     */
    public function isDispatchable(?Carbon $on = null): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->hasCoiExpired($on);
    }

    /** Days until the COI expires (negative = already expired), or null if none is recorded. */
    public function coiDaysToExpiry(): ?int
    {
        return $this->coi_expires_at === null
            ? null
            : (int) Carbon::today()->startOfDay()->diffInDays($this->coi_expires_at->startOfDay(), false);
    }

    /**
     * Which COI alert stage this vendor is in RIGHT NOW, or null when there's nothing to chase
     * (no cert recorded, or one comfortably in date). Drives both `vendors:scan-coi-expiry` and
     * the dashboard card, so the nightly nag and the live count can never disagree.
     */
    public function coiAlertStage(?Carbon $on = null): ?string
    {
        if ($this->coi_expires_at === null || $this->status !== self::STATUS_ACTIVE) {
            return null;
        }

        $days = (int) ($on ?? Carbon::today())->startOfDay()
            ->diffInDays($this->coi_expires_at->startOfDay(), false);

        return match (true) {
            $days < 0 => self::COI_STAGE_EXPIRED,
            $days <= self::COI_ALERT_DAYS => self::COI_STAGE_EXPIRING,
            default => null,
        };
    }

    /** Active vendors whose COI is lapsed or lapsing within the alert window — the chase list. */
    public function scopeCoiNeedsAttention(Builder $query, ?Carbon $on = null): Builder
    {
        $on = ($on ?? Carbon::today())->startOfDay();

        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('coi_expires_at')
            ->whereDate('coi_expires_at', '<=', $on->copy()->addDays(self::COI_ALERT_DAYS)->toDateString());
    }

    /** The dispatchable set — active vendors whose COI (if any) is still valid. */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q
                ->whereNull('coi_expires_at')
                ->orWhereDate('coi_expires_at', '>=', Carbon::today()->toDateString()));
    }

    /**
     * `[id => name]` of dispatchable vendors for a work-order/plan picker, plus `$keepId` (flagged
     * ⚠) when it is no longer assignable — so an EDIT form still shows the currently-assigned
     * vendor rather than a blank select. Server-side, `MaintenanceWorkOrder::saving()` is the gate.
     *
     * @return array<int, string>
     */
    public static function assignableOptions(?int $keepId = null): array
    {
        $options = static::query()->assignable()->orderBy('name')->pluck('name', 'id');

        if ($keepId !== null && ! $options->has($keepId)) {
            $current = static::find($keepId);
            if ($current !== null) {
                $options->put($current->id, $current->name.' ⚠');
            }
        }

        return $options->all();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(VendorContract::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class, 'assigned_to_vendor_id');
    }

    public function primaryContact(): ?VendorContact
    {
        return $this->contacts()->where('is_primary', true)->first()
            ?? $this->contacts()->oldest()->first();
    }

    public function activeContractsCount(): int
    {
        return $this->contracts()->where('status', 'active')->count();
    }

    protected static function booted(): void
    {
        static::creating(function (self $vendor) {
            if (empty($vendor->slug)) {
                $base = Str::slug($vendor->name ?? 'vendor');
                $slug = $base;
                $suffix = 1;
                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base . '-' . (++$suffix);
                }
                $vendor->slug = $slug;
            }
        });
    }
}
