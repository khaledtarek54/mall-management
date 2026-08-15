<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Support\Attributes\NeverDeletable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The disposal (write-off / sale) of a fixed asset (module 23, Phase 2b). One per
 * asset. This is its OWN ledger source — the FixedAssetDisposalJournalizer removes the
 * asset's gross cost + accumulated depreciation and books the gain/loss:
 *
 *   Dr Accumulated Depreciation (accumulated) + Dr Cash|Bank (proceeds) + Dr Loss …
 *       Cr Furniture & Equipment (cost) + Cr Gain …
 *
 * Its GL follows the parent asset's lifecycle (soft-delete / restore / re-home) via
 * FixedAsset::booted() — the child-source windowed-sweep cascade.
 */
#[NeverDeletable(correction: 'reverse the disposal')]
class FixedAssetDisposal extends Model
{
    use RefusesDeletionOfCommittedRecords, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'fixed_asset_id',
        'disposed_on',
        'proceeds',
        'proceeds_account',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'disposed_on' => 'date',
        'proceeds' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['fixed_asset_id', 'disposed_on', 'proceeds', 'proceeds_account'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fixed_asset_disposal');
    }

    /** @return BelongsTo<FixedAsset, $this> */
    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for proceeds (blank form field must not send null).
        static::saving(function (self $disposal) {
            $raw = $disposal->getAttributes()['proceeds'] ?? null;
            if ($raw === null || $raw === '') {
                $disposal->proceeds = 0;
            }
        });
    }
}
