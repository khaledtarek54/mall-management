<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateNotOperatorTyped;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One month's depreciation charge for a fixed asset (module 23). The monthly run
 * (DepreciationService::run / accounting:post-depreciation) creates exactly one per
 * (asset, month); accumulated depreciation = SUM(amount). Each entry posts to the
 * GL as Dr Depreciation Expense / Cr Accumulated Depreciation (Phase 2).
 */
#[NeverDeletable(correction: 'reverse the depreciation run')]
#[PropertyOwned(via: 'fixedAsset')]
#[PostingDateNotOperatorTyped(reason: 'period_month is set by DepreciationService::run from the month being posted; the operator-reachable inputs are the scheduler and the admin button (both now()) and PostDepreciationCommand --month, which is guarded there.')]
class DepreciationEntry extends Model
{
    use HasFactory, LogsActivity, RefusesDeletionOfCommittedRecords, SoftDeletes;

    protected $fillable = [
        'fixed_asset_id',
        'period_month',
        'amount',
        'created_by_user_id',
    ];

    protected $casts = [
        'period_month' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['fixed_asset_id', 'period_month', 'amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('depreciation_entry');
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
