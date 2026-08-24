<?php

namespace App\Models;

use App\Models\Concerns\AllocatesPartyCode;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[DeletableWhenUnused(blockedBy: ['bills', 'contracts', 'tenantRequests', 'documents'], instead: 'set the vendor to inactive (or blacklisted) — it disappears from every assignment picker without losing its bills')]
// shared vendor catalog; engagement per-property (VendorContract/Bill)
#[PortfolioShared]
class Vendor extends Model
{
    use AllocatesPartyCode, HasFactory, HasSearchText, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;
    use HasCustomFields;

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
            $this->code,
            $this->name,
            $this->legal_name,
            $this->tax_id,
            $this->email,
            $this->phone,
            $this->city,
            self::digitsOf($this->phone),

            // The operator's own fields (D-7). `metadata` is this row's own attribute, so this
            // honours the no-relations rule and re-folds whenever the record saves.
            ...$this->customFieldSearchValues(),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'vendor');
    }

    protected $fillable = [
        // The operator's own fields (D-7). A VIRTUAL attribute — `HasCustomFields` routes it
        // through `fillCustomFields()`, which discards keys the catalogue does not define. The
        // `metadata` column itself is deliberately NOT fillable: nothing fills it wholesale.
        'custom_fields',
        // The supplier's own number — the AP side of the same problem `Tenant::$code` solves.
        'code',
        'name',
        'slug',
        'type',
        'status',
        'legal_name',
        'tax_id',
        'withholding_tax_code',
        'withholding_exempt',
        'email',
        'phone',
        'address',
        'city',
        'notes',
    ];

    protected $casts = [
        'metadata' => 'array',
        // Two columns rather than one overloaded value: the code is null when nothing has been
        // ruled for this supplier (use the portfolio default), and `withholding_exempt` says they
        // are outside Egyptian withholding altogether. The old single column expressed the second
        // as a magic 0, which needed explaining everywhere it was read.
        'withholding_exempt' => 'boolean',
    ];

    // ============ Compliance gate (reads vendor_documents) ============

    /** @return HasMany<VendorDocument, $this> */
    /**
     * Bills raised against this vendor.
     *
     * `vendor_bills.vendor_id` has always existed; the inverse had not, so nothing could ask
     * whether a vendor carries financial history — which is what makes deleting one unsafe.
     */
    /**
     * التخصصات — which trades this vendor actually does.
     *
     * The answer to "who may we dispatch to an HVAC fault?", which nothing could answer before
     * 2026-08-20: `vendors` carried `type` (contractor/supplier/…) and no trade at all, so the
     * picker on a work order offered every vendor including the stationery supplier, and
     * `VendorScorecardService` compared a cleaner with an HVAC contractor.
     *
     * Many-to-many because a facilities company does HVAC AND electrical, and pretending otherwise
     * forces an operator to register one company twice.
     */
    public function trades(): BelongsToMany
    {
        return $this->belongsToMany(Trade::class);
    }

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
     *
     * The question is about the CURRENT insurance certificate, not about every row on file. Without
     * `current()` this asked "has this vendor ever held a lapsed COI", so uploading the renewal and
     * keeping last year's — the correct way to maintain a compliance file — **bricked the
     * contractor permanently**, and the only way out was deleting the evidence.
     */
    public function hasExpiredBlockingDocument(?Carbon $on = null): bool
    {
        return $this->documents()->blocking()->current()->expired($on)->exists();
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

    /**
     * The dispatchable set — active vendors whose CURRENT blocking document has not lapsed.
     *
     * The same scope chain as `hasExpiredBlockingDocument()` above, deliberately: these two are one
     * predicate asked of a set and of a row, and a picker that offers a vendor the save guard then
     * refuses is worse than either half being wrong on its own.
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereDoesntHave('documents', function ($q) {
                /** @var Builder<VendorDocument> $q */
                return $q->blocking()->current()->expired();
            });
    }

    /**
     * `[id => name]` of dispatchable vendors for a work-order/plan picker, plus `$keepId` (flagged
     * ⚠) when it is no longer assignable — so an EDIT form still shows the currently-assigned
     * vendor rather than a blank select. Server-side, `FacilityWorkOrder::saving()` is the gate.
     *
     * @return array<int, string>
     */
    public static function assignableOptions(?int $keepId = null, ?int $tradeId = null): array
    {
        $options = static::query()->assignable()->orderBy('name')->pluck('name', 'id');

        if ($keepId !== null && ! $options->has($keepId)) {
            $current = static::find($keepId);
            if ($current !== null) {
                $options->put($current->id, $current->name.' ⚠');
            }
        }

        if ($tradeId === null) {
            return $options->all();
        }

        // **Grouped, never filtered.** Which vendors do this trade is a suggestion: Filament
        // validates a Select against its options with `Rule::in`, so dropping the others would
        // REFUSE a legitimate pick — and the day the usual HVAC contractor is unavailable is a
        // real day. The one thing that genuinely blocks a dispatch stays `assignable()` above:
        // compliance, which is a decision the operator actually made about that vendor.
        $eligible = static::query()
            ->whereHas('trades', fn ($q) => $q->whereKey($tradeId))
            ->pluck('id')
            ->all();

        $groups = [
            __('admin.facility.vendor_groups.for_this_trade') => $options->only($eligible)->all(),
            __('admin.facility.vendor_groups.other') => $options->except($eligible)->all(),
        ];

        // An empty heading renders as a heading with nothing under it, which reads as a bug.
        return array_filter($groups, fn (array $g): bool => $g !== []);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(VendorContract::class);
    }

    public function tenantRequests(): HasMany
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

    /** Numbered from the operator-configurable `vendor` prefix — see AllocatesPartyCode. */
    public static function partyCodeType(): string
    {
        return 'vendor';
    }

    protected static function booted(): void
    {
        static::creating(function (self $vendor) {
            if (empty($vendor->slug)) {
                $base = Str::slug($vendor->name ?? 'vendor');
                $slug = $base;
                $suffix = 1;
                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.(++$suffix);
                }
                $vendor->slug = $slug;
            }
        });
    }
}
