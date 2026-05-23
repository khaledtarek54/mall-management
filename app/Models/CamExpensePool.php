<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CamExpensePool extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUSES = ['draft', 'reconciling', 'reconciled', 'closed'];

    protected $fillable = [
        'asset_id',
        'period_year',
        'total_actual_expense',
        'total_estimated_collected',
        'status',
        'notes',
        'reconciled_at',
        'reconciled_by_user_id',
    ];

    protected $casts = [
        'total_actual_expense' => 'decimal:2',
        'total_estimated_collected' => 'decimal:2',
        'reconciled_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total_actual_expense', 'total_estimated_collected', 'reconciled_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('cam_pool');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CamAllocation::class);
    }

    public function reconciledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function variance(): float
    {
        return (float) $this->total_actual_expense - (float) $this->total_estimated_collected;
    }

    public function isReconciled(): bool
    {
        return in_array($this->status, ['reconciled', 'closed']);
    }
}
