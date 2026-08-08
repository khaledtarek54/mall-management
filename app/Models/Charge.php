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

    /**
     * Where a schedule row came from.
     *
     * A lease's rent is a date-ranged SCHEDULE, not a single mutable amount: a change closes the
     * current row and opens the next. Once several rows can exist per type, "why does this lease
     * have four rent rows" must be answerable from the data. See
     * docs/benchmarks/yardi/01-yardi-lease-administration.md §3.2.
     */
    public const ORIGIN_SEED = 'seed';            // written when the lease was created

    public const ORIGIN_MANUAL = 'manual';        // an operator changed the rent

    public const ORIGIN_ESCALATION = 'escalation'; // the annual escalation sweep

    public const ORIGIN_RENEWAL = 'renewal';      // carried onto a renewal lease

    public const ORIGIN_LEVY = 'levy';            // derived from base rent (marketing levy)

    protected $fillable = [
        'lease_id',
        'name',
        'type',
        'origin',
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

    /**
     * Active rows whose date range covers the given day — the schedule row in force then.
     *
     * Open-ended on either side counts as covering, which is what makes the pre-schedule rows
     * (`start_date` = commencement, `end_date` = null) behave exactly as they always have.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeEffectiveOn($query, \DateTimeInterface $date)
    {
        $d = \Illuminate\Support\Carbon::instance($date)->toDateString();

        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $d))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $d));
    }

    /** True when this row is still open-ended — the last one in its schedule. */
    public function isOpenEnded(): bool
    {
        return $this->end_date === null;
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
