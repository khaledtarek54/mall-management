<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * السنة المالية — a financial year and the window it spans.
 */
#[DeletionAllowed(reason: 'configuration: its periods carry the entries, and they are guarded')]
// one operator fiscal calendar
#[PortfolioShared]
class FiscalYear extends Model
{
    use HasFactory, LogsActivity;

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

    /**
     * The year's close and reopen are on the record, as the month's are (see AccountingPeriod).
     * `YearEndCloseService::reopen()` records its reason here through `App\Support\ReversalReason`.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'fiscal_year');
    }

    /** How the year names itself where it is referenced by id — the audit trail's Changes column. */
    public function label(): string
    {
        return (string) $this->year;
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
