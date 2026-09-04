<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A published index figure — the source a CPI-linked rent escalation measures against.
 *
 * Voyager's **index source** *(cited, `docs/benchmarks/yardi/01-yardi-lease-administration.md`
 * §4)*. One row per index per month: what the statistical agency published, and when.
 *
 * **It records, it does not compute.** The whole reason the escalation sweep skipped CPI for its
 * entire life is that inventing an index number is inventing data, and a rent step is money a
 * tenant pays. A register does not invent — it captures a published figure with the date it became
 * knowable, so three years later the operator can show which figure a step used.
 */
#[DeletionAllowed(reason: 'reference data: a published statistic keyed by mistake is ordinary cleanup, and a figure a lease has already escalated on is protected by the audit trail on the lease, not by keeping the row')]
#[PortfolioShared]
class RentIndex extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'rent_indices';

    protected $fillable = ['code', 'period', 'value', 'published_on', 'notes'];

    protected $casts = [
        'period' => 'date',
        'published_on' => 'date',
        'value' => 'decimal:4',
    ];

    /**
     * The code as it is STORED — trimmed and upper-cased.
     *
     * An index is a SERIES: every reading of one index carries the same code, and a CPI clause
     * looks the base month and the review month up under it. Two spellings are two series of one
     * reading each, and the rent then never steps — reported from the panel by exactly that route
     * (CPI-EG and EGY_CPI, one reading apiece), which is why the form upper-cases on the way in.
     *
     * Named here rather than left inline because two things now need the same answer and they run
     * at different moments: the dehydrator that WRITES the column, and the uniqueness rule that has
     * to look a code up BEFORE any dehydrator has run. A filter written twice is a filter that
     * drifts, and this one would drift silently — a rule keyed on the typed value matches nothing
     * under SQLite's case-sensitive `=`, so it would be green here and different on MySQL.
     */
    public static function normaliseCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'rent_index');
    }

    /**
     * The figure for an index in a given month, or null when it has not been published yet.
     *
     * **Null is the important answer**, and every caller must treat it as "wait", never as zero.
     * Yardi generates the escalation row *when the index publishes*; until then there is nothing to
     * apply, and the sweep that re-runs daily will pick it up the day it lands.
     */
    public static function valueFor(string $code, CarbonImmutable $period): ?float
    {
        $value = static::query()
            ->where('code', $code)
            ->whereDate('period', $period->startOfMonth()->toDateString())
            ->value('value');

        return $value === null ? null : (float) $value;
    }

    /**
     * What an operator reads on a screen — the index and the month it describes, **in their own
     * language** (SW-028). Read by the rent-index picker on the lease form, so `format('M Y')`
     * put an English month in an Arabic dropdown. The em dash is the house placeholder for a
     * missing value, exactly as `JournalNarrative` renders one.
     */
    public function label(): string
    {
        return $this->code.' · '.($this->period?->locale(app()->getLocale())->isoFormat('MMM YYYY') ?? '—');
    }
}
