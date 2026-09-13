<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An option inside a lease — a right one party may exercise inside a stated window.
 *
 * See the migration for why this exists. The short version: the only lease-date alert Atriom had
 * fired 90 days before EXPIRY, which is months after a typical notice window has closed, so the
 * system reliably spoke too late to act.
 */
#[DeletionAllowed(reason: 'parent-managed: the optionality recorded on a lease, edited from it. An option that was never really in the contract is removed; one that WAS is resolved (exercised/lapsed/waived), which keeps the history')]
#[PropertyOwned(via: 'lease.unit')]
class LeaseOption extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const TYPES = ['renewal', 'termination', 'expansion', 'contraction', 'rofr', 'rofo', 'purchase'];

    public const STATUSES = ['open', 'exercised', 'lapsed', 'waived'];

    /** The statuses that record an OUTCOME — none of them is the born state. */
    public const RESOLVED_STATUSES = ['exercised', 'lapsed', 'waived'];

    /**
     * Which statuses an operator may STATE on this record from the tab — the one list the form's
     * Select reads (SW-259, 2026-09-13).
     *
     * A status past the first one is the outcome of an ACT (the rule SW-238 applied to every money
     * document): `exercised` is written by `ExerciseLeaseOptionService` — it checks the notice
     * window, stamps the notice and resolution dates and records the lease event the renewal is
     * raised from — and the dropdown offered it as a word. Picked there it wrote the word and
     * nothing else: no window check, no event, an option reading "exercised" that
     * `pendingRenewalTerms()` then hands to the Renew form as if it were real.
     *
     *  - `exercised` is never offered, on a new record either: a NEW row is not a transition, so
     *    the model still accepts it for a console seeder abstracting history
     *    (`SeedLeasingDepthCommand`), but the tab is the door for options on THIS lease going
     *    forward, and a hand-made exercised row outranks a genuine one in the Renew form;
     *  - an EXERCISED record's status is locked — its exercise is on the lease's record, and no act
     *    un-exercises;
     *  - any other record may be stated as `open`, `waived` or `lapsed` — statements, whose only
     *    consequence is the resolution date, which the model derives when the statement omits it.
     *
     * @return list<string>
     */
    public static function statusesAnOperatorMayState(?self $record): array
    {
        if ($record?->exists && $record->getRawOriginal('status') === 'exercised') {
            return ['exercised'];
        }

        return array_values(array_diff(self::STATUSES, ['exercised']));
    }

    /**
     * Whether the save in flight is the Exercise act. A transient set by {@see markExercised()}
     * for the duration of ITS save and nothing else — the first cut recognised the act by SHAPE
     * (the notice and resolution dates dirty in the same save) and refused the genuine act the
     * moment the notice date had been recorded on the tab beforehand: the service then writes
     * the same date, nothing is dirty, and the operator is told to press the button they pressed.
     */
    private bool $exercisingByTheAct = false;

    /**
     * The ONE way a live option becomes `exercised` — called by `ExerciseLeaseOptionService`
     * after its window check, never from a form.
     *
     * @param  array<string, mixed>  $attributes  the status's companions: `resolved_at`, `notice_given_at`
     */
    public function markExercised(array $attributes): void
    {
        $this->exercisingByTheAct = true;

        try {
            $this->forceFill($attributes + ['status' => 'exercised'])->save();
        } finally {
            $this->exercisingByTheAct = false;
        }
    }

    public const RENT_BASES = ['fixed', 'uplift_percent', 'market', 'cpi'];

    /** Option types that tie up a specific unit until they are resolved. */
    public const ENCUMBERING_TYPES = ['expansion', 'rofr', 'rofo', 'purchase'];

    protected $fillable = [
        'lease_id', 'type', 'status',
        'earliest_notice_date', 'latest_notice_date',
        'term_months', 'rent_basis', 'uplift_percent', 'fixed_rent', 'penalty_amount',
        'unit_id', 'notice_given_at', 'resolved_at', 'notes',
        'opening_notified_at', 'closing_notified_at', 'lapsed_notified_at',
    ];

    protected $casts = [
        'earliest_notice_date' => 'date',
        'latest_notice_date' => 'date',
        'notice_given_at' => 'date',
        'resolved_at' => 'date',
        'term_months' => 'integer',
        'uplift_percent' => 'decimal:2',
        'fixed_rent' => 'decimal:2',
        'penalty_amount' => 'decimal:2',
        'opening_notified_at' => 'datetime',
        'closing_notified_at' => 'datetime',
        'lapsed_notified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // ── The notice window must be a window, not a contradiction ────────────────────────────
        // An option whose window closes before it opens can never be exercised: `isWindowOpen()`
        // wants today >= earliest and `hasWindowClosed()` wants today > latest, so an inverted pair
        // is simultaneously never-open and already-closed. The `leases:scan-option-windows` sweep
        // would announce it as lapsed having never announced it as open, and nothing surfaces the
        // contradiction — the right the tenant negotiated just silently does not work.
        //
        // The rule lived as one `->afterOrEqual()` on the lease's options relation manager, so any
        // other writer (import, API, console, the second relation manager) walked past it.
        //
        // A null bound is unbounded on that side and stays legal — an option with no stated
        // deadline is ordinary. EQUAL is legal too: "notice must be served on 1 September" is a
        // one-day window, not an error.
        static::saving(function (self $option): void {
            if ($option->earliest_notice_date === null || $option->latest_notice_date === null) {
                return;
            } elseif ($option->status === 'waived') {
                $option->resolved_at = CarbonImmutable::today()->toDateString();
            }

            $earliest = CarbonImmutable::instance(
                $option->earliest_notice_date instanceof \DateTimeInterface
                    ? $option->earliest_notice_date
                    : CarbonImmutable::parse($option->earliest_notice_date)
            )->startOfDay();

            $latest = CarbonImmutable::instance(
                $option->latest_notice_date instanceof \DateTimeInterface
                    ? $option->latest_notice_date
                    : CarbonImmutable::parse($option->latest_notice_date)
            )->startOfDay();

            if ($latest->lessThan($earliest)) {
                throw new \DomainException(__('admin.errors.option_notice_window_inverted', [
                    'earliest' => $earliest->toDateString(),
                    'latest' => $latest->toDateString(),
                ]));
            }
        });

        // ── `exercised` is the outcome of an act, never a word typed (SW-259) ─────────────────
        // The Select does not offer it (`statusesAnOperatorMayState()`), and Filament's own
        // `Rule::in` over the options refuses a smuggled value first — this is the layer we own
        // standing behind that contract, and the only one an import or a console act meets. The
        // act identifies ITSELF (`markExercised()`): a shape — the notice and resolution dates
        // dirty together — refused the genuine act whenever the notice date was already on the
        // record, because the service then re-writes the same date and nothing is dirty.
        static::updating(function (self $option): void {
            if (! $option->isDirty('status')) {
                return;
            }

            if ($option->status === 'exercised' && ! $option->exercisingByTheAct) {
                throw new \DomainException(__('admin.errors.option_exercised_is_an_act'));
            }

            // And no act un-exercises: the exercise is on the lease's record (an append-only
            // event) and the renewal is raised from it. The tab's Select is disabled on such a
            // record — a rendering decision, and this is the gate. The correction for an option
            // exercised in error is to remove it and record it again, which the refusal says.
            if ($option->getRawOriginal('status') === 'exercised') {
                throw new \DomainException(__('admin.errors.option_exercised_is_final'));
            }
        });

        // A STATED outcome carries the day it was resolved. `exercised` is the act's to stamp and
        // is left alone (deriving it here would also hand the guard above a dirty stamp it did
        // not earn); a waiver typed on the tab is resolved today, and a lapse — stated by hand or
        // abstracted from history — on the day its window closed, which is the day it lapsed.
        static::saving(function (self $option): void {
            if (! $option->isDirty('status') || $option->resolved_at !== null) {
                return;
            }

            if ($option->status === 'lapsed') {
                $option->resolved_at = ($option->latest_notice_date
                    ? CarbonImmutable::instance($option->latest_notice_date)
                    : CarbonImmutable::today())->toDateString();
            }
        });

        // ── An alert stamp records an alert about a DATE; move the date and the alert is void ──
        // `leases:scan-option-windows` sends each of its three alerts once and stamps it. Nothing
        // ever cleared a stamp — no writer touched them but the scan — so an option whose window
        // was EXTENDED after "the deadline is near" had gone was silent until it lapsed, and one
        // whose opening was moved after "notice may now be served" had gone announced nothing about
        // the new date (SW-258, found by the review of SW-257). The notification's own docblock
        // presupposed a re-alert (*"a re-dated option is visibly a NEW alert"*). Critical-date
        // reminders in the reference systems are computed from the CURRENT date each run, so a
        // changed date is a changed reminder — this is that rule for a scan that remembers.
        //
        // Each stamp goes with the bound its alert was about: the opening with the earliest date,
        // the closing and the lapse with the latest. **A moved START also voids a closing already
        // sent**: SW-257 rightly never announces an opening after a closing, so the closing is the
        // only alert that can carry the corrected window — leaving it stamped would make the last
        // thing the system said about the window wrong, with nothing to follow it. Re-opening a
        // resolved option (`status` back to `open`) forgets its lapse AND its resolution date, so a
        // window still closed is lapsed AGAIN — the operator reopened something already over, and
        // the system says so once more rather than staying quiet. The STATUS is never moved here:
        // an operator correcting the recorded deadline on an option that genuinely lapsed must
        // not find it live and encumbering a unit again.
        //
        // Not forward-only, unlike the lease's expiry reminder (SW-048): these alerts go to the
        // leasing team, never outbound to a tenant, so a deadline brought FORWARD re-firing
        // "decide" sooner is the wanted answer. A caller that states a stamp in the same save has
        // ruled on it itself, exactly as that hook reads it.
        //
        // On the MODEL, for the reason the window rule above is: the tab is one door, and a stamp
        // cleared only there is not cleared for an import, a console act or a second screen.
        static::updating(function (self $option): void {
            $clear = function (string $stamp) use ($option): void {
                if (! $option->isDirty($stamp)) {
                    $option->{$stamp} = null;
                }
            };

            if ($option->isDirty('earliest_notice_date')) {
                $clear('opening_notified_at');
                $clear('closing_notified_at');
            }

            if ($option->isDirty('latest_notice_date')) {
                $clear('closing_notified_at');
                $clear('lapsed_notified_at');
            }

            if ($option->isDirty('status') && $option->status === 'open') {
                $clear('lapsed_notified_at');

                if (! $option->isDirty('resolved_at')) {
                    $option->resolved_at = null;
                }
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'lease_option');
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * The space this option encumbers, if any.
     *
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Has the window opened — i.e. may notice be served today? */
    public function windowIsOpen(?CarbonImmutable $on = null): bool
    {
        $on = $on ?? CarbonImmutable::now()->startOfDay();

        if ($this->earliest_notice_date && CarbonImmutable::instance($this->earliest_notice_date)->greaterThan($on)) {
            return false;
        }

        return ! $this->windowHasClosed($on);
    }

    public function windowHasClosed(?CarbonImmutable $on = null): bool
    {
        $on = $on ?? CarbonImmutable::now()->startOfDay();

        return $this->latest_notice_date !== null
            && CarbonImmutable::instance($this->latest_notice_date)->lessThan($on);
    }

    /**
     * Days until the window shuts — the number that decides urgency. Negative once it has passed,
     * null when the option has no deadline at all.
     */
    public function daysUntilClose(?CarbonImmutable $on = null): ?int
    {
        if (! $this->latest_notice_date) {
            return null;
        }

        $on = $on ?? CarbonImmutable::now()->startOfDay();

        return (int) $on->diffInDays(CarbonImmutable::instance($this->latest_notice_date)->startOfDay(), false);
    }

    /**
     * Does this option tie up a unit right now?
     *
     * Only while it is still OPEN: an exercised, lapsed or waived option encumbers nothing, and
     * treating it as if it did would block space the mall is free to let.
     */
    public function encumbersUnit(): bool
    {
        return $this->isOpen()
            && $this->unit_id !== null
            && in_array($this->type, self::ENCUMBERING_TYPES, true);
    }

    /** The rent this option would produce, when that is knowable without a valuation. */
    public function projectedRent(float $currentRent): ?float
    {
        return match ($this->rent_basis) {
            'fixed' => $this->fixed_rent !== null ? (float) $this->fixed_rent : null,
            'uplift_percent' => $this->uplift_percent !== null
                ? round($currentRent * (1 + (float) $this->uplift_percent / 100), 2)
                : null,
            // 'market' needs a valuation and 'cpi' needs an index feed — neither is a number this
            // system may invent (the same rule the escalation sweep follows).
            default => null,
        };
    }
}
