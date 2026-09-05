<?php

namespace App\Models;

use App\Models\Concerns\RecordsBankAccount;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Services\GenerateRecurringExpensesService;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use Carbon\CarbonImmutable;
use DomainException;
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

    // The column shipped on 2026-09-02 with no relation, no property guard and a bare
    // `EntitySelect` on the form — so a schedule could name ANOTHER MALL's bank account and stamp
    // it onto every cost it generated, which is precisely the cross-property posting the concern
    // exists to refuse. A schedule is not itself a money document, but it DICTATES one.
    use RecordsBankAccount;
    use RefusesDeletionWhenReferenced;
    use SoftDeletes;

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    /** Egyptian real-estate tax is payable in two instalments, which is why this one exists. */
    public const SEMIANNUALLY = 'semiannually';

    public const ANNUALLY = 'annually';

    /** @var array<int, string> */
    public const FREQUENCIES = [self::MONTHLY, self::QUARTERLY, self::SEMIANNUALLY, self::ANNUALLY];

    /**
     * The four columns that state WHEN this schedule books — its window.
     *
     * Named once because two readers need the same set: the `saving` guard asks whether the window
     * MOVED, and the refusal it may raise is worded in terms of all four. `is_active` is
     * deliberately absent — including it would refuse the operator's own escape, which is to switch
     * a dud schedule off rather than repair its dates.
     *
     * @var array<int, string>
     */
    public const WINDOW_COLUMNS = ['starts_on', 'ends_on', 'day_of_month', 'frequency'];

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
        'paid_from',
        'bank_account_id',
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
        return (string) ($this->description ?: $this->category ?: __('admin.recurring_expenses.singular'));
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

    protected static function booted(): void
    {
        // **A SCHEDULE THAT CAN NEVER BOOK IS A COST NOBODY IS EVER ASKED TO PAY.** Four columns
        // state the window — `starts_on`, `ends_on`, `day_of_month`, `frequency` — and three of
        // them interact, so it is easy to state one that contains no scheduled day at all. The row
        // then saves, sits in the register showing an amount and a frequency, and
        // `expenses:generate-recurring` skips it every night with nothing on any screen to say so.
        // On a real-estate tax instalment or a civil-defence licence renewal that silence IS the
        // risk: the cost this screen exists to remember is the one it quietly forgets.
        //
        // Measured at HEAD (e3154f27) by calling `nextDueOn()` with a two-year horizon. All four
        // were saveable through the form, and all four answer null for ever:
        //   `ends_on` 2026-09-01 before `starts_on` 2026-10-01     => null
        //   monthly,   day 1, starts 2026-09-20, ends 2026-09-30   => null
        //   quarterly, day 1, starts 2026-09-20, ends 2026-11-30   => null
        //   annually,  day 1, starts 2026-09-20, ends 2027-01-01   => null
        // The last three are the shape {@see firstScheduledDay()}'s "the first period may not fall
        // before the schedule begins" rule creates: the first cursor steps a whole PERIOD forward,
        // past an end date that looked generous when it was typed. The first predates that rule and
        // is plain nonsense nothing ever refused.
        //
        // **On the MODEL, not on the form.** One screen writes this row today; the guard is here so
        // that the second door — an importer, a seeder, a console backfill — is covered by existing
        // rather than by somebody remembering, which is the reasoning that put `ValueSets::guard()`
        // on a wildcard listener rather than a trait on thirty-nine models.
        //
        // **Refused only when the window MOVES.** A row already in this state stays renameable,
        // re-priceable, switch-off-able and deletable; what is refused is the ACT of stating a
        // window with no day in it. Refusing every save of such a row would take away the
        // operator's own escape — the lockout trap `#[NeverDeletable]` records.
        static::saving(function (self $schedule): void {
            // `starts_on` is NOT NULL in the schema and `required()` on the form. Answering that
            // with a sentence about end dates would be a worse refusal than the one it already has.
            if ($schedule->starts_on === null) {
                return;
            }

            // The contract is part of the window (SW-242): re-linking an existing schedule to a
            // contract that has ended is the edit door onto the same inert row, so it re-asks.
            if ($schedule->exists && ! $schedule->isDirty([...self::WINDOW_COLUMNS, 'vendor_contract_id'])) {
                return;
            }

            // Eloquent keeps a loaded relation when its foreign key moves; the guard must read the
            // contract the row is being SAVED with, not the one it was loaded with.
            if ($schedule->isDirty('vendor_contract_id')) {
                $schedule->unsetRelation('vendorContract');
            }

            if ($schedule->everBooks()) {
                return;
            }

            $first = $schedule->firstScheduledDay();

            // Two refusals, chosen by WHICH bound closed the window — a sentence about "the end
            // date" that quotes the CONTRACT's date sends the operator to clear a field that is not
            // the problem (the two-keys-by-branch rule the write-off cap already follows).
            if ($first !== null && $schedule->contractEndedBy($first)) {
                throw new DomainException(__('admin.refusals.recurring_schedule_contract_ended', [
                    'contract' => $schedule->vendorContract?->name ?? '—',
                    'ends' => $schedule->vendorContract?->end_date?->toDateString() ?? '—',
                ]));
            }

            // Here only because the first scheduled day falls past the schedule's OWN `ends_on`,
            // so both dates exist and the refusal can name the exact day the end date has to clear.
            throw new DomainException(__('admin.refusals.recurring_schedule_never_books', [
                'first' => $first?->toDateString(),
                'ends' => CarbonImmutable::instance($schedule->ends_on)->toDateString(),
            ]));
        });
    }

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
    /**
     * `withTrashed()`, because the contract BOUNDS the schedule (SW-242): a soft-deleted contract
     * keeps its id on this row (soft deletes never fire the column's `nullOnDelete`), and a plain
     * relation answering null would silently lift the bound — the retainer resumes, each new bill
     * pointing at a contract nobody can open.
     */
    public function vendorContract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class)->withTrashed();
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
     *
     * **A PERIOD IS A MONTH, NOT A DATE, AND THE STAMP MUST BE READ THAT WAY.** The walk used to
     * compare the cursor against `last_generated_on` as a plain date, so moving `day_of_month` from
     * the 1st to the 15th on a schedule already generated for September made every cursor in the
     * series land LATER than the stamp — 15 September is after 1 September — and the run that
     * night booked September a second time. The `(recurring_expense_id, expense_date)` UNIQUE index
     * cannot catch it, because the two documents carry two different dates: that is the whole point
     * of the edit. Real money, on a statutory cost, in the direction nobody re-reads.
     *
     * Every frequency here spans at least one whole month, so a calendar month holds at most one
     * period and the month IS the identity of the period. Comparing months makes the day a matter
     * of WHEN in the period it books rather than WHETHER, which is what an operator moving the day
     * means — and it leaves the retroactive direction alone: an earlier `starts_on` still does not
     * back-book months the schedule has already passed.
     */
    public function nextDueOn(CarbonImmutable $on): ?CarbonImmutable
    {
        if (! $this->is_active) {
            return null;
        }

        $months = $this->periodMonths();

        // Where the series begins is {@see firstScheduledDay()}'s answer, not a second walk here.
        // *"When does it book next"* and *"can it ever book"* have to agree about that day, and the
        // guard on `saving` asks the second question — so the first cursor is derived, never
        // re-derived.
        $cursor = $this->firstScheduledDay();

        if ($cursor === null) {
            return null;
        }

        // Skip whole periods already generated. Bounded rather than `while (true)`: a corrupt stamp
        // must not spin a nightly job for ever.
        for ($i = 0; $i < 600; $i++) {
            if ($this->endsBefore($cursor)) {
                return null;
            }

            $alreadyDone = $this->last_generated_on !== null
                && ! $cursor->startOfMonth()->greaterThan(
                    CarbonImmutable::instance($this->last_generated_on)->startOfMonth());

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

    /**
     * The earliest day this schedule's own terms name — the first scheduled day on or after
     * `starts_on`, asked of the TERMS alone: not of `is_active`, and not of what it has booked.
     *
     * {@see nextDueOn()} starts its walk here and {@see everBooks()} asks whether the window still
     * contains it, so *"when does it book next"* and *"can it book at all"* read ONE definition of
     * where the series begins and can never answer differently.
     *
     * **THE FIRST PERIOD MAY NOT FALL BEFORE THE SCHEDULE BEGINS.** `periodDate()` puts the
     * schedule's own day inside the MONTH of `starts_on`, so a schedule that begins on the 20th
     * while `day_of_month` is 1 — the form's default AND the column's — produced a first booking
     * dated the 1st, nineteen days before the operator said the cost begins, for a period that had
     * not started. Measured: `starts_on 2026-09-20`, `day_of_month 1`, monthly, asked on
     * 2026-09-25 => **2026-09-01**; the same shape ANNUALLY => 2026-09-01 and then 2027-09-01, so
     * the whole series ran early for ever.
     *
     * The rule is the one the field's own help states — "the first period booked… earlier periods
     * are never back-filled" — read strictly: the first scheduled day ON OR AFTER `starts_on`. It
     * is also the conservative direction for money going OUT, and the operator has two visible
     * escapes: move `day_of_month` to the day they want, or set `starts_on` to the start of the
     * period they want booked.
     *
     * A whole PERIOD is stepped, not a month, so a quarterly or annual schedule stays anchored to
     * the month of `starts_on`.
     */
    public function firstScheduledDay(): ?CarbonImmutable
    {
        // `starts_on` is NOT NULL in the schema, so this is the in-memory case alone — a model on
        // its way to the database with nothing set on it yet. Answering null lets the `saving`
        // guard stand aside for the `required` refusal that owns that question.
        if ($this->starts_on === null) {
            return null;
        }

        $start = CarbonImmutable::instance($this->starts_on);
        $first = $this->periodDate($start);

        return $first->lessThan($start)
            ? $this->periodDate($first->addMonthsNoOverflow($this->periodMonths()))
            : $first;
    }

    /**
     * Does this schedule's own window contain a scheduled day at all — will it EVER book?
     *
     * Asked of the four terms and of nothing else, so the answer does not move when the schedule is
     * switched off or once it has booked. That is what makes it safe to ask at `saving`: a schedule
     * that has legitimately run its course must stay fully editable.
     */
    public function everBooks(): bool
    {
        $first = $this->firstScheduledDay();

        return $first !== null && ! $this->endsBefore($first);
    }

    /**
     * The day this schedule stops booking — its own `ends_on`, or the linked contract's `end_date`,
     * whichever comes first. Null means open-ended. The last day itself still books, on both
     * bounds: the end date IS a valid booking day, as it always was for `ends_on`.
     *
     * **A recurring cost that names a contract is bounded by that contract's term (SW-242,
     * 2026-09-05).** Until then the schedule read only its own window: a security retainer whose
     * contract ended on the 12th went on raising a draft bill on the 20th of every month — under a
     * contract the register already showed as `expired` — for as long as nobody noticed, which is
     * the failure the month-long staging soak was built to produce. Yardi's recurring payable is a
     * child of the contract and stops with it; here the schedule's own `ends_on` is still honoured,
     * so an operator can end a retainer EARLIER than the contract, never later.
     *
     * A TERMINATED contract is ended whatever its `end_date` says — `VendorContract::saving` dates
     * the termination onto `end_date` when the status moves, so the two agree on a row written
     * through the app; the status check is for rows that were not. A `draft` contract bounds
     * nothing, as before.
     */
    public function effectiveEndsOn(): ?CarbonImmutable
    {
        $own = $this->ends_on !== null ? CarbonImmutable::instance($this->ends_on) : null;

        $contractEnd = $this->vendor_contract_id !== null
            ? $this->vendorContract?->end_date
            : null;
        $contract = $contractEnd !== null ? CarbonImmutable::instance($contractEnd) : null;

        return match (true) {
            $own === null => $contract,
            $contract === null => $own,
            default => $own->lessThan($contract) ? $own : $contract,
        };
    }

    /** Has the linked contract ended by `$day` — by its end date, or by being terminated? */
    public function contractEndedBy(CarbonImmutable $day): bool
    {
        if ($this->vendor_contract_id === null) {
            return false;
        }

        $contract = $this->vendorContract;

        if ($contract === null) {
            return false;
        }

        if ($contract->status === 'terminated') {
            return true;
        }

        return $contract->end_date !== null && $day->greaterThan(CarbonImmutable::instance($contract->end_date));
    }

    /** Has the schedule's window already closed by `$day`? One definition, every reader. */
    private function endsBefore(CarbonImmutable $day): bool
    {
        if ($this->ends_on !== null && $day->greaterThan(CarbonImmutable::instance($this->ends_on))) {
            return true;
        }

        return $this->contractEndedBy($day);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** This schedule calls its rail `paid_from`, not `method`. */
    public static function bankAccountRailColumn(): string
    {
        return 'paid_from';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'recurring_expense');
    }
}
