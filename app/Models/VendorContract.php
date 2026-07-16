<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class VendorContract extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'value', 'start_date', 'end_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor_contract');
    }

    protected $fillable = [
        'vendor_id',
        'asset_id',
        'reference',
        'name',
        'status',
        'start_date',
        'end_date',
        'value',
        'sla_penalty_basis',
        'sla_penalty_rate',
        'currency',
        'scope',
        'notes',
    ];

    protected $casts = [
        'sla_penalty_rate' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'value' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
