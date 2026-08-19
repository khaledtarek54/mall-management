<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyItself;
use App\Support\Occupancy;
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

#[DeletableWhenUnused(blockedBy: ['units', 'leases', 'camPools', 'utilityMeters', 'journalEntries', 'expenses', 'vendorBills', 'payrolls', 'disbursements', 'maintenancePenalties', 'depositTransactions', 'postDatedCheques', 'employees', 'fixedAssets', 'marketingBudgets', 'violations'], instead: 'deactivate the property — deleting one would orphan (or cascade-destroy) every book, payroll, register and penalty that reports on it')]
#[PropertyItself]
class Asset extends Model implements HasMedia
{
    use HasFactory, HasSearchText, InteractsWithMedia, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    /**
     * Reserved code for the synthetic "All Properties" tenant — the
     * pseudo-asset shown in the property switcher that bypasses
     * per-property scoping. Backed by a real DB row so Filament can
     * resolve it from the URL slug.
     */
    public const ALL_PROPERTIES_CODE = 'ALL';

    /**
     * The property's name, its short code (which appears in every document number) and
     * where it is.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->name,
            $this->code,
            $this->city,
            $this->address,
        ];
    }

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
        'is_publicly_listed',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'is_publicly_listed' => 'boolean',
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

    /**
     * The building's floors — B2, B1, G, M, 1 — set up once and selected everywhere else.
     *
     * @return HasMany<Floor, $this>
     */
    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class)->orderBy('level');
    }

    /**
     * The lettable space that is not a shop — parking bays, storage rooms, signage faces.
     *
     * Deliberately NOT part of the property's GLA: a bay is licensed, not leased, and counting it
     * as lettable area would understate every occupancy and recovery percentage the mall reports.
     * See docs/benchmarks/yardi/09-yardi-space-and-parking.md.
     *
     * @return HasMany<RentableItem, $this>
     */
    public function rentableItems(): HasMany
    {
        return $this->hasMany(RentableItem::class)->orderBy('code');
    }

    /**
     * The legal owners of the PROPERTY — Jawad, an admin `User` with the `owner` role, holding a
     * share and a tenure window. This is whose money an owner statement apportions.
     *
     * **Named `propertyOwners`, not `owners`, on purpose.** A unit can be sold to a buyer, and that
     * buyer is an owner too — of one shop, not of the mall, and a `Tenant` (the AR party) rather
     * than a `User`. The two are opposite money directions: a property owner RECEIVES the net, a
     * unit owner PAYS the service charge. `$asset->owners` beside `$unit->owners` was one keystroke
     * away from apportioning a statement to the wrong kind of owner, so neither is called that.
     *
     * @see docs/modules/37-unit-owners.md
     *
     * @return BelongsToMany<User, $this>
     */
    public function propertyOwners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'asset_owner')
            ->using(AssetOwner::class)
            ->withPivot(['id', 'ownership_percentage', 'started_at', 'ended_at'])
            ->withTimestamps();
    }

    /**
     * Staff (admin panel users) assigned to this property. Distinct from
     * `propertyOwners()` which is the legal-ownership relationship.
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
    // NEVER-deletable SlaPenalty — bypassing every model guard). journalEntries is the GL
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
        return $this->hasMany(SlaPenalty::class);
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
    /**
     * Economic occupancy: let area as a percentage of leasable area.
     *
     * Returns 0.0 — not null — when there is no area to divide by. That is a deliberate contract
     * pinned by `AssetOccupancyTest`, and callers rely on a float. The "no units recorded is
     * UNKNOWN, not empty" distinction is real, but it belongs to the SCREEN: the properties table
     * shows "—" when there is nothing to measure, rather than a red 0% that reads as a mall nobody
     * has let.
     *
     * The definition lives in {@see Occupancy}, shared with the dashboard so the two
     * cannot drift apart.
     */
    public function areaOccupancyRate(): float
    {
        return Occupancy::forUnits(Unit::where('asset_id', $this->id))['pct'] ?? 0.0;
    }

    public function occupiedAreaSqm(): float
    {
        return Occupancy::forUnits(Unit::where('asset_id', $this->id))['occupied_sqm'];
    }

    public function totalUnitAreaSqm(): float
    {
        return (float) $this->units()->sum('area_sqm');
    }

    /**
     * Leasable area as a percentage of the building — the load factor.
     *
     * **Why this exists:** `total_area_sqm` was a write-only field. The form asked an operator for
     * the gross building area and NOTHING read it — the same shape as the inert-settings bug, where
     * a screen accepts a number and quietly discards it. It is a real property attribute (GBA
     * against GLA is standard in retail), so it earns its place by answering the question it was
     * always implicitly asking: how much of this building can actually be let.
     *
     * A mall at 70% is normal; the remainder is malls, corridors, plant and back-of-house. A number
     * far outside that usually means one of the two figures is wrong, which is the other reason to
     * put them side by side.
     *
     * Null when the gross area is not recorded — a ratio against zero is not 0%, it is unknown, and
     * reporting 0% would read as a building with nothing lettable in it.
     */
    public function leasableEfficiencyPct(): ?float
    {
        $gross = (float) $this->total_area_sqm;

        if ($gross <= 0) {
            return null;
        }

        // The DECLARED leasable area where there is one, else what the units actually add up to —
        // the same fallback `CamReconciliationService` uses for the GLA denominator, so the two
        // screens cannot disagree about what "leasable" means.
        $leasable = (float) $this->leasable_area_sqm > 0
            ? (float) $this->leasable_area_sqm
            : $this->totalUnitAreaSqm();

        return round($leasable / $gross * 100, 1);
    }
}
