<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * قيد يومية — a journal entry header. Its `lines` must balance
 * (Σ debit = Σ credit). Posted entries are immutable; to undo one you `void`
 * it (which posts a balanced reversing entry).
 */
class JournalEntry extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'number',
        'entry_date',
        'accounting_period_id',
        'description_en',
        'description_ar',
        'source_type',
        'source_id',
        'is_manual',
        'is_closing',
        'status',
        'asset_id',
        'posted_by_user_id',
        'posted_at',
        'voided_at',
        'reversal_of_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'is_manual' => 'boolean',
        'is_closing' => 'boolean',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'entry_date', 'asset_id', 'source_type', 'source_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('journal_entry');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.005;
    }

    public function displayDescription(): string
    {
        $ar = (string) $this->description_ar;
        $en = (string) $this->description_en;

        return app()->getLocale() === 'ar'
            ? ($ar !== '' ? $ar : $en)
            : ($en !== '' ? $en : $ar);
    }

    public static function generateNumber(?\DateTimeInterface $entryDate = null): string
    {
        $entryDate = $entryDate ? Carbon::instance($entryDate) : now();
        $prefix = sprintf('JE-%s-', $entryDate->format('Ym'));

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (empty($entry->number)) {
                $entry->number = static::generateNumber($entry->entry_date);
            }
            // is_manual is fully derived from the source link (no source = manual),
            // set in one place so the various creation paths can never disagree.
            $entry->is_manual = empty($entry->source_type);
        });
    }
}
