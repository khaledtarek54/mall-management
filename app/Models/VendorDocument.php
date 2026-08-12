<?php

namespace App\Models;

use App\Models\Concerns\HasSupersededDocuments;
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
 * A compliance document held on file for a vendor — insurance, tax card, commercial register,
 * social-insurance certificate (module 12b).
 *
 * Replaces the single `vendors.coi_expires_at`. Each document carries its own expiry and its own
 * renewal chase, because in practice they lapse independently and only some of them should stop
 * work being dispatched.
 */
class VendorDocument extends Model implements HasMedia
{
    use InteractsWithMedia, HasSupersededDocuments, LogsActivity, SoftDeletes;

    /** Insurance certificate (COI) — the one that stops a vendor being sent to site. */
    public const TYPE_INSURANCE_COI = 'insurance_coi';

    /** بطاقة ضريبية — required before an Egyptian entity may deal with the supplier. */
    public const TYPE_TAX_CARD = 'tax_card';

    /** سجل تجاري — proof the supplier is a registered trading entity. */
    public const TYPE_COMMERCIAL_REGISTER = 'commercial_register';

    /** شهادة تأمينات اجتماعية — the principal is liable for a contractor's unpaid social insurance. */
    public const TYPE_SOCIAL_INSURANCE = 'social_insurance';

    public const TYPE_TRADE_LICENSE = 'trade_license';
    public const TYPE_OTHER = 'other';

    /**
     * Which document types STOP a vendor being dispatched to work once lapsed.
     *
     * Deliberately just insurance: sending an uninsured contractor onto the mall floor is a
     * liability the operator carries. A lapsed tax card is a finance-department problem — it
     * should be chased loudly, but blocking emergency repairs over paperwork is the wrong trade.
     * Widening this array is the single place to change that decision.
     *
     * @var array<int, string>
     */
    public const BLOCKING_TYPES = [self::TYPE_INSURANCE_COI];

    /** Days before expiry that the renewal chase starts. */
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
            self::TYPE_SOCIAL_INSURANCE,
            self::TYPE_TRADE_LICENSE,
            self::TYPE_OTHER,
        ];
    }

    protected $fillable = [
        'vendor_id',
        'type',
        'reference',
        'issuer',
        'issued_on',
        'expires_on',
        'notes',
        'alert_stage',
        'alert_for',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'alert_for' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'reference', 'issuer', 'issued_on', 'expires_on'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor_document');
    }

    public function registerMediaCollections(): void
    {
        // Never omit useDisk — medialibrary's default is fail-open ('public'), and a supplier's
        // tax card / insurance certificate is confidential.
        $this->addMediaCollection('file')->useDisk('local');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Does a lapse of THIS document stop the vendor being sent to work? */
    public function isBlocking(): bool
    {
        return in_array($this->type, self::BLOCKING_TYPES, true);
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

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('type', self::BLOCKING_TYPES);
    }

    public function documentOwnerColumn(): string
    {
        return 'vendor_id';
    }
}
