<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * السنة المالية — a financial year and the window it spans.
 */
#[DeletionAllowed(reason: 'configuration: its periods carry the entries, and they are guarded')]
// one operator fiscal calendar
#[PortfolioShared]
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
