<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterReading extends Model
{
    use HasFactory;

    protected $fillable = [
        'utility_meter_id',
        'reading_date',
        'reading_value',
        'consumption',
        'cost',
        'notes',
    ];

    protected $casts = [
        'reading_date' => 'date',
        'reading_value' => 'decimal:2',
        'consumption' => 'decimal:2',
        'cost' => 'decimal:2',
    ];

    public function meter(): BelongsTo
    {
        return $this->belongsTo(UtilityMeter::class, 'utility_meter_id');
    }

    protected static function booted(): void
    {
        // cost is NOT NULL (DDL default 0); a blank/optional form field
        // dehydrates null, which bypasses the default — coerce it to 0.
        static::saving(function (self $reading) {
            if ($reading->cost === null) {
                $reading->cost = 0;
            }
        });
    }
}
