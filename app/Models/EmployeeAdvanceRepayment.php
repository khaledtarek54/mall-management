<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One repayment against an employee advance/loan (module 24, Phase 2). Posts to the
 * GL as Dr Cash|Bank / Cr Employee Advances, reducing the receivable. A CHILD ledger
 * source of the advance — its GL follows the advance's lifecycle (EmployeeAdvance's
 * booted() cascade). `asset_id` is denormalised from the advance for the GL dimension.
 */
class EmployeeAdvanceRepayment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'employee_advance_id',
        'asset_id',
        'amount',
        'repaid_on',
        'method',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'repaid_on' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_advance_id', 'asset_id', 'amount', 'repaid_on', 'method'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('employee_advance_repayment');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
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
        static::saving(function (self $repayment) {
            $raw = $repayment->getAttributes()['amount'] ?? null;
            if ($raw === null || $raw === '') {
                $repayment->amount = 0;
            }
        });
    }
}
