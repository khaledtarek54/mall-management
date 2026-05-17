<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Charge extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['lease_id', 'name', 'type', 'amount', 'frequency', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('charge');
    }

    protected $fillable = [
        'lease_id',
        'name',
        'type',
        'amount',
        'currency',
        'frequency',
        'vat_applicable',
        'vat_rate',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_applicable' => 'boolean',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function calculateVat(): float
    {
        if (! $this->vat_applicable) {
            return 0;
        }
        return round($this->amount * ($this->vat_rate / 100), 2);
    }

    public function totalWithVat(): float
    {
        return (float) ($this->amount + $this->calculateVat());
    }
}
