<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
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

    protected $fillable = [
        'asset_id',
        'unit_id',
        'area_id',
        'equipment_id',
        'days_of_week',
        'title',
        'category',
        'plan_type',
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
    ];

    /** NOT-NULL with a DB default — never let a blank form field send null. */
    protected $attributes = [
        'plan_type' => self::MAINTENANCE_TYPE_ROUTINE,
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
            $this->category,
            $this->description,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'unit_id', 'area_id', 'equipment_id', 'title', 'category', 'plan_type', 'frequency_unit', 'frequency_value', 'days_of_week', 'next_due_date', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('service_plan');
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

    public function workOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Active plans whose next occurrence is on/before the given date (default today). */
    public function scopeDue(Builder $query, ?string $onOrBefore = null): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('next_due_date', '<=', $onOrBefore ?? now()->toDateString());
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
