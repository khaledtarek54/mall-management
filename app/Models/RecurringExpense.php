<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Services\GenerateRecurringExpensesService;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * مصروف دوري — a cost that comes round every period whether or not anyone remembers it (EG-33).
 *
 * Recurrence existed only on the revenue side: `charges` bill a lease every cycle, and every cost
 * arriving on a calendar rather than on an invoice was somebody's reminder. Real-estate tax,
 * municipal levies, the annual civil-defence licence, a fixed retainer. Yardi calls these Recurring
 * Payables.
 *
 * ## It mints expenses; it is not one
 *
 * {@see GenerateRecurringExpensesService} creates an {@see Expense} per due period,
 * and THAT posts to the ledger through the journalizer that already exists. The schedule must never
 * be registered in `LedgerPoster::JOURNALIZERS` — it would post every levy twice, and balance both
 * times. Same reasoning that keeps a facility work order a cost object rather than a GL source.
 *
 * ## The amount is the operator's figure, not a computed one
 *
 * Egyptian real-estate tax has a rate, a rental-value basis, a 32% non-residential maintenance
 * deduction and an assessment issued per property. None of that is modelled here on purpose: the
 * assessed figure is a fact the operator holds, and computing it from guessed rates would produce a
 * confident wrong number on a statutory filing.
 */
// BOTH relations. A schedule that names a vendor raises `vendorBills`, never `expenses`, so
// listing only the first would leave every supplier schedule freely deletable — the blocked_by
// under-population trap, and the gate verifies the relations EXIST but cannot know one is missing.
#[DeletableWhenUnused(
    blockedBy: ['expenses', 'vendorBills'],
    instead: 'Switch it off. A schedule that has already booked costs explains why those expenses and supplier bills exist, and every P&L and CAM pool that read them is downstream of it.',
)]
#[PropertyOwned]
class RecurringExpense extends Model
{
    use LogsActivity;
    use RefusesDeletionWhenReferenced;
    use SoftDeletes;

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    /** Egyptian real-estate tax is payable in two instalments, which is why this one exists. */
    public const SEMIANNUALLY = 'semiannually';

    public const ANNUALLY = 'annually';

    /** @var array<int, string> */
    public const FREQUENCIES = [self::MONTHLY, self::QUARTERLY, self::SEMIANNUALLY, self::ANNUALLY];

    /** How many months one period spans. */
    public const MONTHS_PER_PERIOD = [
        self::MONTHLY => 1,
        self::QUARTERLY => 3,
        self::SEMIANNUALLY => 6,
        self::ANNUALLY => 12,
    ];

    protected $fillable = [
        'asset_id',
        'vendor_id',
        'vendor_contract_id',
        'description',
        'category',
        'amount',
        'tax_code',
        'frequency',
        'day_of_month',
        'payment_terms_days',
        'starts_on',
        'ends_on',
        'last_generated_on',
        'is_active',
        'notes',
    ];

    /**
     * How a schedule names itself wherever it is referenced by id — an activity diff, a picker.
     *
     * Its own `description` is what the operator typed ("Real-estate tax — Atriom Walk"), so that
     * is the name; the category is the fallback for a row imported without one, because printing a
     * bare id in a Changes cell tells the reader nothing.
     */
    public function label(): string
    {
        return (string) ($this->description ?: $this->category ?: __('admin.resources.recurring_expense.singular'));
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'day_of_month' => 'integer',
        'payment_terms_days' => 'integer',
        'starts_on' => 'immutable_date',
        'ends_on' => 'immutable_date',
        'last_generated_on' => 'immutable_date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'frequency' => self::MONTHLY,
        'day_of_month' => 1,
        'is_active' => true,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The supplier this standing cost is owed to — null for a cost with no counterparty.
     *
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The agreement it runs under, where there is one.
     *
     * @return BelongsTo<VendorContract, $this>
     */
    public function vendorContract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class);
    }

    /**
     * Does this schedule raise a PAYABLE, or spend money outright?
     *
     * `expenses` carries no `vendor_id` at all — an expense is money leaving with no creditor — so
     * naming a supplier IS the statement that this cost is owed to somebody. One question, asked
     * of the row, rather than a second `type` column that could disagree with the vendor on it.
     */
    public function billsAVendor(): bool
    {
        return $this->vendor_id !== null;
    }

    /** When a bill dated `$on` falls due — 0 terms mean due on issue. */
    public function dueOn(CarbonImmutable $on): CarbonImmutable
    {
        return $on->addDays(max(0, (int) $this->payment_terms_days));
    }

    /** What this schedule has already booked — and what makes it undeletable once it has. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** The other half of the same question: the supplier bills it has raised. */
    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    /** How many months one period of this schedule spans. */
    public function periodMonths(): int
    {
        return self::MONTHS_PER_PERIOD[$this->frequency] ?? 1;
    }

    /**
     * The date of the next period due on or before `$on`, or null when nothing is due.
     *
     * Walks forward from `starts_on` in whole periods rather than adding to `last_generated_on`:
     * a schedule switched off for six months and back on must not silently mint six back-dated
     * expenses, and it must not lose its place either. The first period on or after the stamp is
     * the answer, and the sweep asks again tomorrow.
     *
     * The day is CLAMPED to the month's length, so a schedule set to the 31st does not skip the
     * seven months that are shorter — the same trap `BillingDay` records.
     */
    public function nextDueOn(CarbonImmutable $on): ?CarbonImmutable
    {
        if (! $this->is_active) {
            return null;
        }

        $months = $this->periodMonths();
        $cursor = $this->periodDate(CarbonImmutable::instance($this->starts_on));

        // Skip whole periods already generated. Bounded rather than `while (true)`: a corrupt stamp
        // must not spin a nightly job for ever.
        for ($i = 0; $i < 600; $i++) {
            if ($this->ends_on !== null && $cursor->greaterThan(CarbonImmutable::instance($this->ends_on))) {
                return null;
            }

            $alreadyDone = $this->last_generated_on !== null
                && ! $cursor->greaterThan(CarbonImmutable::instance($this->last_generated_on));

            if (! $alreadyDone) {
                return $cursor->greaterThan($on) ? null : $cursor;
            }

            $cursor = $this->periodDate($cursor->addMonthsNoOverflow($months));
        }

        return null;
    }

    /** The schedule's own day within a month, clamped so a 31 never skips February. */
    private function periodDate(CarbonImmutable $month): CarbonImmutable
    {
        return $month->day(min(max($this->day_of_month, 1), $month->daysInMonth));
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'recurring_expense');
    }
}
