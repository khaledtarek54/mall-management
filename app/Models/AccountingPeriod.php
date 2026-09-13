<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * الفترة المحاسبية — a single month within a fiscal year. Posting into a `closed`
 * period is refused by JournalPostingService.
 */
#[DeletableWhenUnused(blockedBy: ['entries'], instead: 'a period that has been posted to is part of the books; close it rather than remove it')]
// one operator period calendar
#[PortfolioShared]
class AccountingPeriod extends Model
{
    use HasFactory, LogsActivity, RefusesDeletionWhenReferenced;

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

    /**
     * Closing and reopening a month are on the record (2026-09-13). A reopen lifts
     * `App\Support\SealedPeriod`'s whole guard over every posting source — the single widest act in
     * the accounting module — and until now it was a bare status flip nobody could trace: the
     * benchmark's own rule, *reopen sparingly and with proper documentation*, lived in nobody's
     * memory. The `updated` row names who and when — for a month closed on its own row and for the
     * twelve a year's close walks through (model saves, never a bulk update). A month's reopen
     * files its WHY here through `PeriodService::reopenPeriod()`; a YEAR's reopen files it once, on
     * the fiscal year (`reopenFiscalYear()`). Both read `App\Support\ReversalReason`.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'accounting_period');
    }

    /**
     * How this period names itself wherever it is referenced by id — the activity log's Changes
     * column, chiefly. Language-neutral by construction (a number and a year), so it needs no
     * translation. Same `label()` convention as ChargeCode / LedgerAccount / Equipment.
     */
    public function label(): string
    {
        return 'P'.$this->period_no.($this->starts_on ? ' '.$this->starts_on->format('Y') : '');
    }

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
        $d = Carbon::instance($date)->startOfDay();

        return static::query()
            ->whereDate('starts_on', '<=', $d)
            ->whereDate('ends_on', '>=', $d)
            ->first();
    }
}
