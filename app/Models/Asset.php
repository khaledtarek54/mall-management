<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Asset extends Model implements HasMedia
{
    use RefusesDeletionWhenReferenced, HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    /**
     * Reserved code for the synthetic "All Properties" tenant — the
     * pseudo-asset shown in the property switcher that bypasses
     * per-property scoping. Backed by a real DB row so Filament can
     * resolve it from the URL slug.
     */
    public const ALL_PROPERTIES_CODE = 'ALL';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'type', 'city', 'leasable_area_sqm', 'is_active', 'primary_color'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('asset');
    }

    protected $fillable = [
        'name',
        'code',
        'type',
        'address',
        'city',
        'country',
        'total_area_sqm',
        'leasable_area_sqm',
        'currency',
        'primary_color',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'total_area_sqm' => 'decimal:2',
        'leasable_area_sqm' => 'decimal:2',
    ];

    public function isAllProperties(): bool
    {
        return $this->code === self::ALL_PROPERTIES_CODE;
    }

    // ============ Per-property branding ============

    /**
     * MediaLibrary collections — `logo` (top-nav brand) and `favicon`
     * (browser tab icon). Single-file each: re-uploading replaces.
     */
    /**
     * Branding — the only media in the system that is DELIBERATELY public. A property's
     * logo/favicon are rendered as plain URLs by the panel (see `logoUrl()`), so they must
     * be web-reachable; there is nothing confidential in a mall's logo.
     *
     * `useDisk('public')` is stated rather than inherited on purpose. Every other
     * collection is private, and the default these would otherwise fall back to is
     * `env('MEDIA_DISK', 'public')` — a fail-OPEN default that silently exposed lease and
     * tenant documents until 2026-07-16. Making the public choice explicit is what lets
     * MediaPrivacyConformanceTest treat "no explicit disk" as a bug rather than a maybe.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->useDisk('public')->singleFile();
        $this->addMediaCollection('favicon')->useDisk('public')->singleFile();
    }

    /**
     * Public URL for the property's logo, or null if no custom logo
     * is uploaded. AdminPanelProvider falls back to the platform
     * Atriom logo when this returns null.
     */
    public function logoUrl(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }

    public function faviconUrl(): ?string
    {
        return $this->getFirstMediaUrl('favicon') ?: null;
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function camPools(): HasMany
    {
        return $this->hasMany(CamExpensePool::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'asset_owner')
            ->using(AssetOwner::class)
            ->withPivot(['id', 'ownership_percentage', 'started_at', 'ended_at'])
            ->withTimestamps();
    }

    /**
     * Staff (admin panel users) assigned to this property. Distinct from
     * `owners()` which is the legal-ownership relationship.
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'asset_user')
            ->withPivot(['assigned_at', 'ended_at', 'notes'])
            ->withTimestamps();
    }

    public function utilityMeters(): HasMany
    {
        return $this->hasMany(UtilityMeter::class);
    }

    public function leases(): HasManyThrough
    {
        return $this->hasManyThrough(Lease::class, Unit::class);
    }

    // History-bearing children with a direct asset_id — listed so DeletionPolicy's blocked_by can
    // REFUSE deleting a property that carries money / GL / HR history. Without them, a property with
    // financial history but no units was deletable, and a force-delete cascade-destroyed the money
    // and statutory records outright (their asset_id FK is cascadeOnDelete — including a
    // NEVER-deletable MaintenancePenalty — bypassing every model guard). journalEntries is the GL
    // catch-all (every posting stamps asset_id); the direct money records are listed too so an
    // UNposted one still blocks. Pre-go-live deletion-policy review.

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }

    public function maintenancePenalties(): HasMany
    {
        return $this->hasMany(MaintenancePenalty::class);
    }

    public function depositTransactions(): HasMany
    {
        return $this->hasMany(DepositTransaction::class);
    }

    public function postDatedCheques(): HasMany
    {
        return $this->hasMany(PostDatedCheque::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function fixedAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }

    public function marketingBudgets(): HasMany
    {
        return $this->hasMany(MarketingBudget::class);
    }

    public function violations(): HasMany
    {
        return $this->hasMany(Violation::class);
    }

    // ============ Derived metrics ============

    public function occupancyRate(): float
    {
        $total = $this->units()->count();
        if ($total === 0) {
            return 0;
        }
        $occupied = $this->units()->where('status', 'occupied')->count();
        return round(($occupied / $total) * 100, 1);
    }

    public function vacantUnitsCount(): int
    {
        return $this->units()->where('status', 'vacant')->count();
    }

    public function occupiedUnitsCount(): int
    {
        return $this->units()->where('status', 'occupied')->count();
    }

    /**
     * Economic (GLA) occupancy — occupied leasable area ÷ total leasable area, as a percentage.
     *
     * `occupancyRate()` above gives every unit one vote, so a 20 m² kiosk and a 2,000 m² anchor
     * count the same; but rent tracks area, so the area-weighted figure is the one that moves
     * revenue. The denominator is summed bottom-up from the units (NOT the declared
     * `leasable_area_sqm` column), so numerator and denominator always share the same scope —
     * the ratio can never exceed 100% just because the declared GLA and the unit areas disagree.
     * Units with no recorded area contribute nothing to either side (you can't weight by an area
     * you don't have), so incomplete area data shows up as economic occupancy drifting from the
     * unit-count figure — that gap is a real data-quality signal, not a bug.
     */
    public function areaOccupancyRate(): float
    {
        $total = $this->totalUnitAreaSqm();
        if ($total <= 0) {
            return 0;
        }

        return round(($this->occupiedAreaSqm() / $total) * 100, 1);
    }

    public function occupiedAreaSqm(): float
    {
        return (float) $this->units()->where('status', 'occupied')->sum('area_sqm');
    }

    public function totalUnitAreaSqm(): float
    {
        return (float) $this->units()->sum('area_sqm');
    }
}
