<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One settlement against a custody (module 25) — an `expense` (Dr Expense by category /
 * Cr Custodies) or a `return` of unspent cash (Dr Cash|Bank / Cr Custodies). A CHILD
 * ledger source of the custody; its GL follows the custody's lifecycle (Custody
 * booted() cascade). `asset_id` is denormalised from the custody for the GL dimension.
 */
#[DeletionAllowed(reason: 'parent-managed: removed on settlement')]
#[PropertyOwned]
#[PostingDateGuardedBy(guard: \App\Services\SettleCustodyService::class)]
class CustodyTransaction extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'custody_id',
        'asset_id',
        'type',
        'amount',
        'transaction_date',
        'category',
        'method',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['custody_id', 'asset_id', 'type', 'amount', 'transaction_date', 'category', 'method'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('custody_transaction');
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(Custody::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $transaction) {
            $raw = $transaction->getAttributes()['amount'] ?? null;
            if ($raw === null || $raw === '') {
                $transaction->amount = 0;
            }
        });
    }
}
