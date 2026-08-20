<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A recurring preventive-maintenance schedule (module 26) — "what to check, how often,
 * where". When due, the `facility:generate-preventive` scan raises a work order with
 * this plan's checklist, then advances `next_due_date` by the frequency.
 */
#[DeletionAllowed(reason: 'configuration: a PPM schedule')]
#[PropertyOwned]
class ServicePlan extends Model
{
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    /** FR-PPM-02 — `years` was added 2026-07; see advanceDue() for the trap it closed. */
    public const FREQUENCY_UNITS = ['days', 'weeks', 'months', 'years'];

    /**
     * FR-PPM-01 — Routine = recurring on a schedule; Fixed = tied to a specific machine.
     *
     * ⚠️ The FRD defines Fixed as "performed on a defined one-time **or periodic** basis
     * per asset", which is two different things. Only the unambiguous half is encoded here:
     * the discriminator, and the requirement that a Fixed plan names its equipment. Both
     * types still recur — a one-time plan is achieved by deactivating it after its first
     * run. Whether "one-time" needs first-class support is an open client question; do not
     * guess it into the schema.
     */
    public const MAINTENANCE_TYPE_ROUTINE = 'routine';

    public const MAINTENANCE_TYPE_FIXED = 'fixed';

    public const MAINTENANCE_TYPES = [self::MAINTENANCE_TYPE_ROUTINE, self::MAINTENANCE_TYPE_FIXED];

    /**
     * What makes this plan due: the calendar, or a counter.
     *
     * An XOR, and the reason is in the migration. "Whichever comes first" is a real CMMS pattern
     * and is deliberately NOT built — it needs its own reset semantics (does the calendar restart
     * when the usage trigger fires?) and nobody has said which answer they want. It arrives as a
     * third value here plus one branch in the generator, with no migration.
     */
    public const TRIGGER_TIME = 'time';

    public const TRIGGER_USAGE = 'usage';

    public const TRIGGERS = [self::TRIGGER_TIME, self::TRIGGER_USAGE];

    protected $fillable = [
        'asset_id',
        'unit_id',
        'area_id',
        'equipment_id',
        'days_of_week',
        'title',
        'trade_id',
        'plan_type',
        'trigger_type',
        'utility_meter_id',
        'usage_threshold',
        'description',
        'frequency_unit',
        'frequency_value',
        'checklist',
        'department_id',
        'vendor_id',
        'next_due_date',
        'last_generated_at',
        'is_active',
    ];

    protected $casts = [
        'checklist' => 'array',
        'days_of_week' => 'array',
        'next_due_date' => 'date',
        'last_generated_at' => 'datetime',
        // Deliberately NOT fillable: the generator writes these, no form does. A stamp an operator
        // could clear by hand would say "generating fine" about a plan that is not.
        'last_generation_failed_at' => 'datetime',
        'is_active' => 'boolean',
        'frequency_value' => 'integer',
        'usage_threshold' => 'decimal:2',
        // Written by the generator and by the baseline hook, never by a form: it is the plan's
        // record of what the counter read when it was last serviced, and an operator editing it by
        // hand would move the next service without meaning to.
        'usage_at_last_generation' => 'decimal:2',
    ];

    /** NOT-NULL with a DB default — never let a blank form field send null. */
    protected $attributes = [
        'plan_type' => self::MAINTENANCE_TYPE_ROUTINE,
        'trigger_type' => self::TRIGGER_TIME,
    ];

    /**
     * Plan title and what it covers.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->title,
            $this->description,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'unit_id', 'area_id', 'equipment_id', 'title', 'trade_id', 'plan_type', 'trigger_type', 'utility_meter_id', 'usage_threshold', 'frequency_unit', 'frequency_value', 'days_of_week', 'next_due_date', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('service_plan');
    }

    /** التخصص — what kind of work this is. See {@see Trade}. */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The location this work targets — soft services (cleaning, landscaping) are area-scoped. */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** The machine this plan services (FR-PPM-01/03) — null = property/unit-wide. */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * **Is this plan actually being done?** (Maximo §6 — PM compliance.)
     *
     * The percentage of this plan's SETTLED cycles that were completed on or before the day they
     * were due. Settled means the answer is known: completed, or overdue with nobody having done
     * it. A cycle still inside its window is excluded — counting it as a failure would make every
     * plan look bad the day after it generated, and counting it as a success would be a claim
     * nobody can make yet.
     *
     * Null when nothing has settled: a new plan has no compliance, and showing 0% or 100% would
     * both be inventions.
     *
     * **Per PLAN rather than one portfolio number**, because "87% compliant" tells an operator
     * nothing they can act on, while "the generator monthly test-run is 40%" names the thing to fix.
     */
    public function complianceRate(): ?float
    {
        $settled = $this->workOrders()->pmOnTime()->count()
            + $this->workOrders()->pmLate()->count()
            + $this->workOrders()->pmOverdue()->count();

        if ($settled === 0) {
            return null;
        }

        return round($this->workOrders()->pmOnTime()->count() / $settled * 100, 1);
    }

    /**
     * The same figure for a LIST, in one query instead of four per row.
     *
     * `complianceRate()` is the definition; this is how a table reads it without an N+1. Both go
     * through the same three scopes, so they cannot disagree about what "on time" means.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithComplianceCounts(Builder $query): Builder
    {
        return $query->withCount([
            'workOrders as pm_on_time_count' => fn ($q) => $q->pmOnTime(),
            'workOrders as pm_late_count' => fn ($q) => $q->pmLate(),
            'workOrders as pm_overdue_count' => fn ($q) => $q->pmOverdue(),
        ]);
    }

    /** The list's counterpart to {@see complianceRate}, reading the counts the scope loaded. */
    public function complianceRateFromCounts(): ?float
    {
        $settled = (int) ($this->pm_on_time_count ?? 0)
            + (int) ($this->pm_late_count ?? 0)
            + (int) ($this->pm_overdue_count ?? 0);

        return $settled === 0
            ? null
            : round((int) $this->pm_on_time_count / $settled * 100, 1);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active TIME plans whose next occurrence is on/before the given date (default today).
     *
     * **The `trigger_type` filter is load-bearing.** `next_due_date` is NOT NULL, so a usage plan
     * carries one too — without this clause it would match here as well as on its counter and raise
     * two work orders for one service. Time plans are unaffected: the column defaults to `time`.
     */
    public function scopeDue(Builder $query, ?string $onOrBefore = null): Builder
    {
        return $query->where('is_active', true)
            ->where('trigger_type', self::TRIGGER_TIME)
            ->whereDate('next_due_date', '<=', $onOrBefore ?? now()->toDateString());
    }

    /**
     * Active USAGE plans that are wired up enough to be evaluated.
     *
     * Deliberately does NOT decide due-ness in SQL. That comparison needs the meter's latest
     * reading against the plan's baseline, which is a per-row lookup, and doing it in a join here
     * would put the rule in two places — the scan would then be the only thing that knew it, and
     * {@see isDueByUsage()} could drift from it without any test noticing.
     */
    public function scopeUsageTriggered(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('trigger_type', self::TRIGGER_USAGE)
            ->whereNotNull('utility_meter_id')
            ->where('usage_threshold', '>', 0);
    }

    /**
     * Advance next_due_date by the plan's frequency (in-memory; caller persists).
     *
     * Every unit is matched explicitly and an unknown one throws. This used to end in
     * `default => $base->addMonths($step)`, which meant an unrecognised unit was silently
     * treated as MONTHS — so a plan set to "every 1 year" would have quietly fired twelve
     * times a year, generating twelve work orders and twelve inspections that nobody
     * ordered. A loud failure on corrupt data beats a plausible wrong answer.
     *
     * Reaching the throw needs a `frequency_unit` outside FREQUENCY_UNITS, which the model
     * guard below and the form's Select both prevent — so it is a backstop for a direct DB
     * edit, an import, or a half-finished future unit. GeneratePreventiveWorkOrdersService
     * catches per plan, so one corrupt row cannot halt generation for every property.
     *
     * @throws InvalidArgumentException on an unknown frequency unit
     */
    /** Is this plan stuck — did its last generation attempt fail and never recover? */
    public function generationIsFailing(): bool
    {
        return $this->last_generation_failed_at !== null;
    }

    /** @return BelongsTo<UtilityMeter, $this> */
    public function utilityMeter(): BelongsTo
    {
        return $this->belongsTo(UtilityMeter::class);
    }

    public function isUsageTriggered(): bool
    {
        return $this->trigger_type === self::TRIGGER_USAGE;
    }

    /**
     * The counter's latest value, or null when nothing has been read yet.
     *
     * `withTrashed()` on the meter for the reason every journalizer parent-lookup has it: a
     * soft-deleted meter would null the relation, the plan would silently stop evaluating, and a
     * statutory service would stop happening with nothing on screen to say so. A retired meter is a
     * reason to fix the plan, not to make it quietly inert.
     */
    public function latestUsageReading(): ?float
    {
        $meterId = $this->utility_meter_id;

        if ($meterId === null) {
            return null;
        }

        $value = MeterReading::query()
            ->where('utility_meter_id', $meterId)
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->value('reading_value');

        return $value === null ? null : (float) $value;
    }

    /**
     * How much the counter has moved since this plan was last serviced.
     *
     * Null when it cannot be answered — no meter, no reading, or no baseline — because "unknown" and
     * "zero" must not look the same here: zero would read as "not due yet" forever on a plan that is
     * actually misconfigured, and that is the silent-failure shape module 26 already learned to
     * avoid with `last_generation_failed_at`.
     */
    public function usageSinceLastGeneration(): ?float
    {
        $latest = $this->latestUsageReading();
        $baseline = $this->usage_at_last_generation;

        if ($latest === null || $baseline === null) {
            return null;
        }

        // A meter that rolled over or was replaced reads LOWER than the baseline. Clamped at 0
        // rather than reported negative: the plan is not due, and the operator re-baselines by
        // saving the plan. Returning a negative would make the arithmetic below silently true the
        // moment the counter passed the old baseline again.
        return max(0.0, $latest - (float) $baseline);
    }

    /** Has the counter moved far enough to raise the next service? */
    public function isDueByUsage(): bool
    {
        if (! $this->isUsageTriggered() || ! $this->is_active) {
            return false;
        }

        $threshold = (float) ($this->usage_threshold ?? 0);
        $since = $this->usageSinceLastGeneration();

        return $threshold > 0 && $since !== null && $since >= $threshold;
    }

    public function advanceDue(): void
    {
        $base = CarbonImmutable::parse($this->next_due_date);
        $step = max(1, (int) $this->frequency_value);

        $next = match ($this->frequency_unit) {
            'days' => $base->addDays($step),
            'weeks' => $base->addWeeks($step),
            'months' => $base->addMonths($step),
            'years' => $base->addYears($step),
            default => throw new InvalidArgumentException(
                "Maintenance plan #{$this->id} has an unknown frequency_unit '{$this->frequency_unit}'."
            ),
        };

        // Soft-service rounds often run on set weekdays ("every Mon/Wed/Fri"). When days_of_week is
        // set, roll forward to the next permitted ISO weekday (1=Mon … 7=Sun). Empty/null = any day,
        // which is the original behaviour. Bounded to 7 hops, so a nonsense list can't spin.
        $allowed = array_values(array_filter(array_map('intval', (array) ($this->days_of_week ?? []))));
        if ($allowed !== []) {
            for ($hop = 0; $hop < 7 && ! in_array($next->isoWeekday(), $allowed, true); $hop++) {
                $next = $next->addDay();
            }
        }

        $this->next_due_date = $next->toDateString();
        $this->last_generated_at = now();
    }

    protected static function booted(): void
    {
        // Seed (and re-seed) the usage baseline from the counter's current value whenever a plan
        // starts watching a meter, or is pointed at a different one. Without it the first delta is
        // measured from zero against a meter that has been counting for years, and the first
        // nightly run raises a backlog of services that were never actually missed.
        static::saving(function (self $plan): void {
            if ($plan->trigger_type !== self::TRIGGER_USAGE || $plan->utility_meter_id === null) {
                return;
            }

            if ($plan->usage_at_last_generation !== null && ! $plan->isDirty('utility_meter_id')) {
                return;
            }

            $plan->usage_at_last_generation = $plan->latestUsageReading() ?? 0.0;
        });

        static::saving(function (self $plan) {
            // frequency_value must be at least 1, else the plan would never advance.
            if ((int) $plan->frequency_value < 1) {
                $plan->frequency_value = 1;
            }

            // Keep advanceDue()'s throw unreachable from the app: a bad unit can never be
            // written in the first place.
            if (! in_array($plan->frequency_unit, self::FREQUENCY_UNITS, true)) {
                throw new InvalidArgumentException(
                    "Unknown frequency_unit '{$plan->frequency_unit}'; expected one of: ".implode(', ', self::FREQUENCY_UNITS).'.'
                );
            }

            if (! in_array($plan->plan_type, self::MAINTENANCE_TYPES, true)) {
                throw new InvalidArgumentException(
                    "Unknown plan_type '{$plan->plan_type}'; expected one of: ".implode(', ', self::MAINTENANCE_TYPES).'.'
                );
            }

            // The equipment rules below are WRITE-TIME validation, so they only run when
            // something they judge actually changes.
            //
            // Running them on every save was a trap I set and then caught by probing: the
            // nightly scan calls $plan->save() after advanceDue(), touching only
            // next_due_date. Re-validating there meant a plan whose machine had since moved
            // property — or been hard-deleted, since nullOnDelete nulls equipment_id at the
            // DB, behind Eloquent's back, leaving a `fixed` plan with no machine — threw on
            // every subsequent save and raised ZERO work orders from then on. A fire pump
            // that silently stops being inspected is far worse than a stale link. (The scan
            // reports such failures rather than swallowing them, but a plan that can never
            // be saved again is not a state to design for.)
            //
            // Skipping the re-check leaves at most a stale link, which the table renders as
            // '—'. The move itself is blocked at the other end: Equipment refuses to change
            // property while a plan or work order references it.
            if (! $plan->isDirty(['asset_id', 'equipment_id', 'plan_type'])) {
                return;
            }

            // FR-PPM-01: Fixed maintenance is "per asset" — it must name its machine.
            if ($plan->plan_type === self::MAINTENANCE_TYPE_FIXED && $plan->equipment_id === null) {
                throw new InvalidArgumentException('A fixed-maintenance plan must target a specific piece of equipment.');
            }

            // Property isolation: the machine must stand in this plan's property, or the
            // plan would raise work orders against another mall's equipment. The DB cannot
            // express it, so the model is the enforcement point (mirrors Equipment's own
            // parent rule).
            if ($plan->equipment_id !== null) {
                $equipmentAssetId = Equipment::withTrashed()->whereKey($plan->equipment_id)->value('asset_id');

                if ($equipmentAssetId === null) {
                    throw new InvalidArgumentException("Equipment #{$plan->equipment_id} does not exist.");
                }

                if ((int) $equipmentAssetId !== (int) $plan->asset_id) {
                    throw new InvalidArgumentException(
                        "Equipment #{$plan->equipment_id} belongs to another property; a plan can only service equipment in its own property."
                    );
                }
            }
        });
    }
}
