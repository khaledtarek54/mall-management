<?php

namespace App\Models;

use App\Models\Concerns\HasSupersededDocuments;
use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A compliance document held on file for a TENANT — above all the certificate of insurance.
 *
 * The mirror of {@see VendorDocument}, and deliberately so: the chase, the alert stamp and the
 * expiry arithmetic are the same problem, and a second implementation of them is how two lists of
 * lapsing paperwork come to disagree. What differs is the consequence, below.
 *
 * **Nothing is blocked when one of these lapses.** `VendorDocument::BLOCKING_TYPES` exists because
 * there is a dispatch decision to intercept — an uninsured contractor can simply not be sent to
 * site. A sitting tenant has no equivalent: you cannot un-let the shop because a policy expired.
 * So this chases and surfaces, and the operator acts on it. An automatic consequence here would be
 * a business rule nobody agreed, which is why there is no `BLOCKING_TYPES` constant to copy.
 */
#[DeletionAllowed(reason: 'operational: superseded by a newer certificate')]
class TenantDocument extends Model implements HasMedia
{
    use InteractsWithMedia, HasSupersededDocuments, LogsActivity, SoftDeletes;

    /** شهادة تأمين — public-liability cover, normally naming the landlord as additional insured. */
    public const TYPE_INSURANCE_COI = 'insurance_coi';

    /** بطاقة ضريبية — needed before the tenant can be invoiced as a registered entity. */
    public const TYPE_TAX_CARD = 'tax_card';

    /** سجل تجاري — proof the retailer is a registered trading entity. */
    public const TYPE_COMMERCIAL_REGISTER = 'commercial_register';

    /** رخصة تشغيل — the operating licence for the shop itself. */
    public const TYPE_TRADE_LICENSE = 'trade_license';

    /** خطاب ضمان — a bank guarantee standing in place of, or beside, the cash deposit. */
    public const TYPE_BANK_GUARANTEE = 'bank_guarantee';

    public const TYPE_OTHER = 'other';

    /** Days before expiry that the renewal chase starts. Matches the vendor chase. */
    public const ALERT_DAYS = 30;

    public const STAGE_EXPIRING = 'expiring';

    public const STAGE_EXPIRED = 'expired';

    /** @return array<int, string> */
    public static function types(): array
    {
        return [
            self::TYPE_INSURANCE_COI,
            self::TYPE_TAX_CARD,
            self::TYPE_COMMERCIAL_REGISTER,
            self::TYPE_TRADE_LICENSE,
            self::TYPE_BANK_GUARANTEE,
            self::TYPE_OTHER,
        ];
    }

    /** Localized value => label map for Filament selects + table formatting. */
    public static function typeOptions(): array
    {
        $out = [];

        foreach (self::types() as $type) {
            $out[$type] = __("admin.enums.tenant_document_type.{$type}");
        }

        return $out;
    }

    protected $fillable = [
        'tenant_id',
        'type',
        'reference',
        'issuer',
        'issued_on',
        'expires_on',
        'coverage_amount',
        'notes',
        'alert_stage',
        'alert_for',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'alert_for' => 'date',
        'coverage_amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'reference', 'issuer', 'issued_on', 'expires_on', 'coverage_amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tenant_document');
    }

    public function registerMediaCollections(): void
    {
        // Never omit useDisk — medialibrary's default is fail-open ('public'), and a retailer's
        // tax card or insurance certificate is confidential. `MediaPrivacyConformanceTest` gates it.
        $this->addMediaCollection('file')->useDisk('local');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Days until expiry (negative = already lapsed), or null when no expiry is tracked. */
    public function daysToExpiry(?Carbon $on = null): ?int
    {
        return $this->expires_on === null
            ? null
            : (int) ($on ?? Carbon::today())->startOfDay()->diffInDays($this->expires_on->startOfDay(), false);
    }

    public function hasExpired(?Carbon $on = null): bool
    {
        $days = $this->daysToExpiry($on);

        return $days !== null && $days < 0;
    }

    /** Where this document sits in the renewal chase right now, or null if there's nothing to chase. */
    public function alertStage(?Carbon $on = null): ?string
    {
        $days = $this->daysToExpiry($on);

        return match (true) {
            $days === null => null,
            $days < 0 => self::STAGE_EXPIRED,
            $days <= self::ALERT_DAYS => self::STAGE_EXPIRING,
            default => null,
        };
    }

    /**
     * Documents lapsed or lapsing inside the alert window — the chase list.
     *
     * Scoped to the CURRENT document of each type. A superseded certificate is history, and history
     * cannot be renewed: leaving it in here means the party is chased forever for a document they
     * already replaced, and a nag that can never be cleared is a nag people learn to close.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsAttention(Builder $query, ?Carbon $on = null): Builder
    {
        $on = ($on ?? Carbon::today())->startOfDay();

        return $query->current()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $on->copy()->addDays(self::ALERT_DAYS)->toDateString());
    }

    /** Documents that have actually lapsed. */
    public function scopeExpired(Builder $query, ?Carbon $on = null): Builder
    {
        return $query->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', ($on ?? Carbon::today())->startOfDay()->toDateString());
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function documentOwnerColumn(): string
    {
        return 'tenant_id';
    }
}
