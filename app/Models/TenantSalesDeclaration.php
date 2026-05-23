<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TenantSalesDeclaration extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUSES = ['submitted', 'locked', 'disputed'];

    protected $fillable = [
        'lease_id',
        'period_start',
        'period_end',
        'declared_sales',
        'calculated_percentage_rent',
        'declared_at',
        'declared_by_type',
        'declared_by_id',
        'status',
        'locked_at',
        'locked_by_user_id',
        'audit_notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'declared_at' => 'datetime',
        'locked_at' => 'datetime',
        'declared_sales' => 'decimal:2',
        'calculated_percentage_rent' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'declared_sales', 'calculated_percentage_rent', 'locked_at', 'audit_notes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tenant_sales');
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function declaredBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function periodLabel(): string
    {
        return $this->period_start->isoFormat('MMM YYYY');
    }
}
