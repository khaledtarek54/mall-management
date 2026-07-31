<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الفترة المحاسبية — a single month within a fiscal year. Posting into a `closed`
 * period is refused by JournalPostingService.
 */
class AccountingPeriod extends Model
{
    use RefusesDeletionWhenReferenced, HasFactory;

    protected $fillable = [
        'fiscal_year_id',
        'period_no',
        'starts_on',
        'ends_on',
        'status',
    ];

    protected $casts = [
        'period_no' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** The period whose date window contains the given date, if any. */
    public static function forDate(\DateTimeInterface $date): ?self
    {
        $d = \Illuminate\Support\Carbon::instance($date)->startOfDay();

        return static::query()
            ->whereDate('starts_on', '<=', $d)
            ->whereDate('ends_on', '>=', $d)
            ->first();
    }
}
