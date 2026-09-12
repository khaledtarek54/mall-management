<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Services\TransferFixedAssetService;
use App\Support\ActivityLogging;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One side of a {@see FixedAssetTransfer} — the GL source for ONE property.
 *
 * `out` is dimensioned to the property the asset left: Cr Furniture & Equipment (cost),
 * Dr Accumulated Depreciation (what had accumulated), Dr Inter-property clearing (the net book
 * value). `in` is the mirror in the property it joined. Two sources rather than one entry with
 * mixed lines, because every financial statement scopes on `journal_entries.asset_id` and a line's
 * own dimension is read by the CAM pool alone — a single entry would put one mall's half of the
 * transfer into the other mall's balance sheet.
 *
 * Committed on creation and never edited: the service writes both legs from one locked read of the
 * asset, and the figures are the act's own, frozen. Undone only by transferring back.
 */
#[NeverDeletable(correction: 'transfer the asset back — a second act, dated, with its own reason')]
#[PropertyOwned]
#[PostingDateGuardedBy(guard: TransferFixedAssetService::class)]
class FixedAssetTransferLeg extends Model
{
    use LogsActivity, RefusesDeletionOfCommittedRecords, SoftDeletes;

    public const OUT = 'out';

    public const IN = 'in';

    public const DIRECTIONS = [self::OUT, self::IN];

    protected $fillable = [
        'fixed_asset_transfer_id',
        'fixed_asset_id',
        'asset_id',
        'direction',
        'transferred_on',
        'cost',
        'accumulated_depreciation',
    ];

    protected $casts = [
        'transferred_on' => 'date',
        'cost' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'fixed_asset_transfer_leg');
    }

    /**
     * How a leg names itself where it is referenced by id — the journal's Source column, the
     * audit trail: the transfer's own label plus which side this is. Codes and a date, language
     * neutral (the `AccountingPeriod::label()` convention).
     */
    public function label(): string
    {
        return ($this->transfer?->label() ?? '#'.$this->fixed_asset_transfer_id).' · '.$this->direction;
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(FixedAssetTransfer::class, 'fixed_asset_transfer_id')->withTrashed();
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class)->withTrashed();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** The net book value the leg moves — what the clearing account carries for this property. */
    public function netBookValue(): float
    {
        return round(max(0.0, (float) $this->cost - (float) $this->accumulated_depreciation), 2);
    }
}
