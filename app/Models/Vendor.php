<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Vendor extends Model
{
    use RefusesDeletionWhenReferenced, HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BLACKLISTED = 'blacklisted';

    /**
     * Trading and legal name, tax id, and how to reach them.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->name,
            $this->legal_name,
            $this->tax_id,
            $this->email,
            $this->phone,
            $this->city,
            self::digitsOf($this->phone),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'status', 'email', 'phone', 'tax_id', 'withholding_tax_rate'])
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
        'withholding_tax_rate',
        'email',
        'phone',
        'address',
        'city',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        // Nullable on purpose: null = fall back to the portfolio default rate, an explicit 0 =
        // this supplier is exempt. Collapsing the two would silently start withholding from an
        // exempt vendor the next time the default changed.
        'withholding_tax_rate' => 'decimal:2',
    ];

    // ============ Compliance gate (reads vendor_documents) ============

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<VendorDocument, $this> */
    /**
     * Bills raised against this vendor.
     *
     * `vendor_bills.vendor_id` has always existed; the inverse had not, so nothing could ask
     * whether a vendor carries financial history — which is what makes deleting one unsafe.
     */
    public function bills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    /** @return HasMany<VendorDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class);
    }

    public function isBlacklisted(): bool
    {
        return $this->status === self::STATUS_BLACKLISTED;
    }

    /**
     * Is a document whose lapse should stop work (currently: insurance) actually lapsed?
     *
     * A vendor with NO such document on file is not "expired" — v1 doesn't retro-demand a
     * certificate from every existing supplier; blacklist one to hard-block it. That was the
     * rule when this read a single `coi_expires_at` column and it survives the move to
     * VendorDocument unchanged.
     */
    public function hasExpiredBlockingDocument(?Carbon $on = null): bool
    {
        return $this->documents()->blocking()->expired($on)->exists();
    }

    /**
     * May this vendor be dispatched to work? Active status (so blacklisted/inactive are out)
     * AND no lapsed blocking document.
     */
    public function isDispatchable(?Carbon $on = null): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->hasExpiredBlockingDocument($on);
    }

    /**
     * Active vendors holding a document lapsed or lapsing inside the alert window — the chase list.
     *
     * Shared by `vendors:scan-document-expiry`, the Action Required card and the table filter, so
     * the nightly nag, the live count and the list can never disagree about who needs chasing.
     */
    public function scopeDocumentsNeedAttention(Builder $query, ?Carbon $on = null): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereHas('documents', function ($q) use ($on) {
                /** @var Builder<VendorDocument> $q */
                return $q->needsAttention($on);
            });
    }

    /** The dispatchable set — active vendors with no lapsed blocking document. */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereDoesntHave('documents', function ($q) {
                /** @var Builder<VendorDocument> $q */
                return $q->blocking()->expired();
            });
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
