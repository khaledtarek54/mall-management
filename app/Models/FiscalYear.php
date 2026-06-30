<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * السنة المالية — a financial year and the window it spans.
 */
class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'starts_on',
        'ends_on',
        'status',
    ];

    protected $casts = [
        'year' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
