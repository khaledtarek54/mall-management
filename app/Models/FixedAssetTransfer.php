<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Support\ActivityLogging;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A fixed asset moved from one property to another — the ACT (meeting 2026-09-02, point 18).
 *
 * Dated, reasoned, and immutable: it records where the asset went, when, why and what it carried
 * (its cost and the depreciation accumulated by then, frozen here rather than re-derived). It posts
 * nothing itself — its two {@see FixedAssetTransferLeg}s are the GL sources, one per property,
 * because a statement here scopes on the entry's own property dimension and one entry carries one.
 * SAP's ABUMN and Yardi's asset transfer have the same shape: history stays where it was, the
 * balance moves on the transfer date, and future depreciation follows the asset.
 *
 * Written by `TransferFixedAssetService` only; there is no form onto this row. The asset's
 * `propertyOn($date)` reads these rows to say which property held it in any month, which is what
 * keeps the acquisition and every posted depreciation entry dimensioned where they were.
 */
#[NeverDeletable(correction: 'transfer the asset back — a second act, dated, with its own reason')]
#[PropertyOwned(via: 'fixedAsset')]
class FixedAssetTransfer extends Model
{
    use LogsActivity, RefusesDeletionOfCommittedRecords, SoftDeletes;

    protected $fillable = [
        'fixed_asset_id',
        'from_asset_id',
        'to_asset_id',
        'transferred_on',
        'cost',
        'accumulated_depreciation',
        'reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'transferred_on' => 'date',
        'cost' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'fixed_asset_transfer');
    }

    /**
     * How a transfer names itself where it is referenced by id — the legs' audit rows, chiefly:
     * the asset's tag, the two malls' codes and the date. Codes and a date, so it reads the same
     * in either language and needs no translation (the `AccountingPeriod::label()` convention).
     */
    public function label(): string
    {
        return sprintf(
            '%s: %s → %s (%s)',
            $this->fixedAsset?->tag ?? '#'.$this->fixed_asset_id,
            $this->fromAsset?->code ?? '#'.$this->from_asset_id,
            $this->toAsset?->code ?? '#'.$this->to_asset_id,
            $this->transferred_on?->format('Y-m-d') ?? '',
        );
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class)->withTrashed();
    }

    public function fromAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'from_asset_id');
    }

    public function toAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'to_asset_id');
    }

    /** @return HasMany<FixedAssetTransferLeg, $this> */
    public function legs(): HasMany
    {
        return $this->hasMany(FixedAssetTransferLeg::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
