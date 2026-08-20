<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Notifications\WorkOrderAssignedNotification;
use App\Services\NotifyAreaSupervisorsService;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use App\Support\SlaResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A preventive-maintenance work order (module 26) — a scheduled facility job raised from
 * a plan or ad-hoc. Carries a checklist (its items); once the work is done the order is
 * marked `done` (a terminal, immutable state, like closed maintenance requests).
 */
#[DeletionAllowed(reason: 'operational: a job record')]
#[PropertyOwned]
class FacilityWorkOrder extends Model implements HasMedia
{
    use HasFactory, HasSearchText, InteractsWithMedia, LogsActivity, SoftDeletes;

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    public const TERMINAL = ['done', 'cancelled'];

    /** Planned/preventive vs corrective (FR-CM-01). */
    public const TYPE_PPM = 'ppm';

    public const TYPE_CM = 'cm';

    public const TYPES = [self::TYPE_PPM, self::TYPE_CM];

    /** FR-CM-02 — in-house staff vs a third-party company. CM only. */
    public const EXECUTION_INTERNAL = 'internal';

    public const EXECUTION_EXTERNAL = 'external';

    public const EXECUTION_TYPES = [self::EXECUTION_INTERNAL, self::EXECUTION_EXTERNAL];

    /**
     * FR-CM-06 asks for at least Normal + Urgent. This is the 4-tier superset module 11
     * already speaks (Normal ≈ medium), so the two halves of maintenance don't end up
     * disagreeing about what "urgent" means.
     */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /**
     * FR-CM-12/13 — who caused the failure. The finding, recorded on the work order.
     *
     * Deliberately a short, closed vocabulary: it feeds a derivation (`bearerFor()`), and free
     * text cannot be derived from. `undetermined` is a real answer, not a missing one — "we looked
     * and we cannot tell" is different from nobody having looked yet (which is `fault_party` null).
     */
    public const FAULT_TENANT = 'tenant';           // the occupier caused it — misuse, neglect, their fit-out

    public const FAULT_WEAR = 'wear_and_tear';      // nobody caused it; things age

    public const FAULT_VENDOR = 'vendor';           // the contractor who last worked on it caused it

    public const FAULT_THIRD_PARTY = 'third_party'; // a visitor, a neighbour, someone we don't bill

    public const FAULT_FORCE_MAJEURE = 'force_majeure'; // flood, power surge, act of God

    public const FAULT_UNDETERMINED = 'undetermined';

    public const FAULT_PARTIES = [
        self::FAULT_TENANT,
        self::FAULT_WEAR,
        self::FAULT_VENDOR,
        self::FAULT_THIRD_PARTY,
        self::FAULT_FORCE_MAJEURE,
        self::FAULT_UNDETERMINED,
    ];

    /** FR-CM-13 — "whether the mall or the tenant is financially responsible". Exactly two. */
    public const BEARER_MALL = 'mall';

    public const BEARER_TENANT = 'tenant';

    public const COST_BEARERS = [self::BEARER_MALL, self::BEARER_TENANT];

    /**
     * Work-order reference and what the job is.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->title,
            $this->description,
        ];
    }

    /**
     * FR-CM-13: the bearer is decided "based on who caused the damage" — so it is *derived*, not
     * typed in independently. Only the tenant causing it makes the tenant liable; everything else
     * lands on the mall.
     *
     * **Vendor fault maps to the mall on purpose.** It is tempting to read "the vendor broke it"
     * as "the vendor pays", but FR-CM-13 offers only mall|tenant — recovering from a vendor is a
     * different mechanism entirely (the SLA penalty against their bill, FR-CM-08). Between the two
     * parties this field is about, a vendor's mistake is the mall's problem, and the mall pursues
     * its contractor separately. Encoding "vendor" here would quietly answer a question the FRD
     * did not ask.
     *
     * `undetermined` lands on the mall because you cannot bill someone on a shrug — the burden of
     * proof sits with the party making the claim.
     */
    public static function bearerFor(string $faultParty): string
    {
        return $faultParty === self::FAULT_TENANT ? self::BEARER_TENANT : self::BEARER_MALL;
    }

    protected $fillable = [
        'service_plan_id',
        'work_order_type',
        'execution_type',
        'asset_id',
        'unit_id',
        'area_id',
        'equipment_id',
        'reference',
        'title',
        'trade_id',
        'status',
        'priority',
        'scheduled_for',
        'acknowledged_at',
        'target_resolution_at',
        'target_response_at',
        'sla_breach_notified_at',
        'response_breach_notified_at',
        'completed_at',
        'completed_by_user_id',
        'department_id',
        'vendor_id',
        'assigned_to_user_id',
        'notes',
        'description',
        // Estimates are operator-entered; ACTUALS are never fillable — they are derived by
        // recomputeCosts(), and a form that could set them would be a second truth about the money.
        'est_labour_hours', 'est_labour_cost', 'est_material_cost', 'est_service_cost',
        'failure_problem_id', 'failure_cause_id', 'failure_remedy_id',
        'source_item_id',
        'parent_work_order_id',
        'tenant_request_id',
        'fault_party',
        'cost_bearer',
        'fault_notes',
        'fault_recorded_by_user_id',
        'fault_recorded_at',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'acknowledged_at' => 'datetime',
        'target_resolution_at' => 'datetime',
        'target_response_at' => 'datetime',
        'sla_breach_notified_at' => 'datetime',
        'response_breach_notified_at' => 'datetime',
        'completed_at' => 'datetime',
        'est_labour_hours' => 'decimal:2',
        'est_labour_cost' => 'decimal:2',
        'est_material_cost' => 'decimal:2',
        'est_service_cost' => 'decimal:2',
        'est_total_cost' => 'decimal:2',
        'act_labour_hours' => 'decimal:2',
        'act_labour_cost' => 'decimal:2',
        'act_material_cost' => 'decimal:2',
        'act_service_cost' => 'decimal:2',
        'act_total_cost' => 'decimal:2',
        'fault_recorded_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'open',
        'work_order_type' => self::TYPE_PPM,
        'priority' => 'medium',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['service_plan_id', 'work_order_type', 'execution_type', 'asset_id', 'unit_id', 'area_id', 'equipment_id', 'title', 'trade_id', 'status', 'priority', 'scheduled_for', 'acknowledged_at', 'target_response_at', 'target_resolution_at', 'completed_at', 'vendor_id', 'assigned_to_user_id', 'parent_work_order_id', 'tenant_request_id', 'fault_party', 'cost_bearer', 'fault_notes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('facility_work_order');
    }

    /** Planned work, done on time. */
    public const PM_ON_TIME = 'on_time';

    /** Planned work, done — but after the date it was due. */
    public const PM_LATE = 'late';

    /** Planned work, not done, and the date has passed. The finding. */
    public const PM_OVERDUE = 'overdue';

    /** Planned work still in its window. Not yet anything. */
    public const PM_DUE = 'due';

    /**
     * **Was this preventive job done when it was supposed to be?** (Maximo §6.)
     *
     * `scheduled_for` on a generated order IS the plan's `next_due_date` — the generator copies it
     * — so compliance is exactly "completed on or before the day it was due". Both dates have been
     * stored since the module shipped; nothing ever compared them, so a preventive programme was a
     * list of intentions.
     *
     * **Measured strictly, with no tolerance window, and that is a stated deviation from Maximo.**
     * Maximo allows a PM tolerance, and a single global one would be wrong in both directions here:
     * three days is most of a weekly cleaning round and nothing at all on an annual overhaul. A
     * percentage of the cycle would be a policy nobody has agreed to. Strict never OVERSTATES
     * compliance — it is the safe direction — and the `late` rows are visible for an operator to
     * judge. Revisit with a per-plan tolerance if the operator asks, not before.
     *
     * Returns null where the question does not apply: a corrective job answers to its SLA instead,
     * and a cancelled one was never going to happen.
     */
    public function pmComplianceState(?CarbonImmutable $on = null): ?string
    {
        if ($this->work_order_type !== self::TYPE_PPM || $this->status === 'cancelled') {
            return null;
        }

        if ($this->scheduled_for === null) {
            return null;
        }

        $due = CarbonImmutable::parse($this->scheduled_for)->endOfDay();

        if ($this->completed_at !== null) {
            return CarbonImmutable::parse($this->completed_at)->lte($due) ? self::PM_ON_TIME : self::PM_LATE;
        }

        return ($on ?? CarbonImmutable::now())->gt($due) ? self::PM_OVERDUE : self::PM_DUE;
    }

    /**
     * The query twin of {@see pmComplianceState}'s `overdue` — planned work nobody has done.
     *
     * Shared by the filter and the plan's compliance count so they cannot drift about what
     * "overdue" means.
     */
    public function scopePmOverdue(Builder $query, ?CarbonImmutable $on = null): Builder
    {
        return $query
            ->where('work_order_type', self::TYPE_PPM)
            ->where('status', '!=', 'cancelled')
            ->whereNull('completed_at')
            ->whereNotNull('scheduled_for')
            ->whereDate('scheduled_for', '<', ($on ?? CarbonImmutable::now())->toDateString());
    }

    /** The query twin of `late`: done, but after the day it was due. */
    public function scopePmLate(Builder $query): Builder
    {
        return $query
            ->where('work_order_type', self::TYPE_PPM)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('completed_at')
            ->whereNotNull('scheduled_for')
            // date() on both sides: completing at 16:00 on the due date is ON TIME, and comparing
            // a datetime against a date column would call every afternoon completion late.
            ->whereRaw('date(completed_at) > date(scheduled_for)');
    }

    /** The query twin of `on_time`. */
    public function scopePmOnTime(Builder $query): Builder
    {
        return $query
            ->where('work_order_type', self::TYPE_PPM)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('completed_at')
            ->whereNotNull('scheduled_for')
            ->whereRaw('date(completed_at) <= date(scheduled_for)');
    }

    /** What was observed. {@see FailureCode} */
    public function failureProblem(): BelongsTo
    {
        return $this->belongsTo(FailureCode::class, 'failure_problem_id');
    }

    /** Why it happened. */
    public function failureCause(): BelongsTo
    {
        return $this->belongsTo(FailureCode::class, 'failure_cause_id');
    }

    /** What was done about it. */
    public function failureRemedy(): BelongsTo
    {
        return $this->belongsTo(FailureCode::class, 'failure_remedy_id');
    }

    /**
     * **Has this been fixed before, recently?** (ServiceChannel §4.)
     *
     * The highest-value cheap signal in retail FM: it identifies the fault that was never actually
     * fixed, and the contractor who keeps coming back to bill twice. Scenario S6 — the same
     * escalator handrail four times in five weeks, four invoices, and a register showing four
     * unrelated successes.
     *
     * **Same THING, not merely the same property.** A machine when the job names one; otherwise the
     * unit, because a shop is what a tenant reports about. Two jobs in the same mall are not a
     * repeat of each other and counting them so would make every busy property look like a failure.
     *
     * Trade-matched as well: an electrical fault and a plumbing fault in one shop are two problems,
     * not one recurring one.
     *
     * A FOLLOW-UP is excluded. `parent_work_order_id` says the operator already knows this job came
     * out of that one — it is a continuation somebody planned, not a fault that came back.
     *
     * Counted BEFORE this job, never after: the question is "did we already fix this?", and a later
     * visit is the next job's finding, not this one's.
     */
    public function scopeRepeatsOf(Builder $query, self $order, ?int $days = null): Builder
    {
        $days = $days ?? (int) config('facility.repeat_visit_days', 30);
        $since = CarbonImmutable::parse($order->created_at ?? now())->subDays($days);

        return $query
            ->whereKeyNot($order->getKey())
            ->where('status', '!=', 'cancelled')
            ->where('trade_id', $order->trade_id)
            ->whereNull('parent_work_order_id')
            ->when(
                $order->equipment_id !== null,
                fn (Builder $q) => $q->where('equipment_id', $order->equipment_id),
                // No machine named: fall back to the SHOP. Refuse to match on nothing — without
                // this guard a job with neither would "repeat" every other job in the trade.
                fn (Builder $q) => $order->unit_id === null
                    ? $q->whereRaw('1 = 0')
                    : $q->where('unit_id', $order->unit_id)->whereNull('equipment_id'),
            )
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $order->created_at ?? now());
    }

    /**
     * The same count for a LIST, in ONE query instead of one per row.
     *
     * `priorVisitCount()` is the definition; this is how a table reads it without an N+1 — the same
     * pairing as `ServicePlan::complianceRate()` and its count-based twin, and pinned by a test that
     * the two agree, because a badge disagreeing with the record it links to is worse than no badge.
     *
     * Measured before it existed: 14 queries for 12 rows, on a column that is not hidden by default.
     *
     * A correlated self-subquery, aliased — `whereColumn` against the outer `facility_work_orders`
     * needs the inner copy under a different name. Written with `addSelect([alias => query])` rather
     * than a raw `select *, (…)`, which SQLite accepts and MySQL rejects.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithPriorVisitCount(Builder $query, ?int $days = null): Builder
    {
        $days = $days ?? (int) config('facility.repeat_visit_days', 30);

        // Date arithmetic relative to EACH row has no portable spelling: SQLite wants
        // `datetime(col, '-30 days')`, MySQL wants `date_sub(col, interval 30 day)`. Branched here
        // in one place rather than discovered on the first real deploy — the suite runs SQLite and
        // would never have told us. `$days` is an int, so there is nothing to inject.
        $cutoff = $query->getConnection()->getDriverName() === 'sqlite'
            ? "datetime(facility_work_orders.created_at, '-{$days} days')"
            : "date_sub(facility_work_orders.created_at, interval {$days} day)";

        return $query->addSelect(['prior_visit_count' => static::query()
            ->from('facility_work_orders as prior')
            ->selectRaw('count(*)')
            ->whereColumn('prior.id', '!=', 'facility_work_orders.id')
            ->whereColumn('prior.trade_id', 'facility_work_orders.trade_id')
            ->where('prior.status', '!=', 'cancelled')
            ->whereNull('prior.parent_work_order_id')
            ->whereNull('prior.deleted_at')
            // The same "same THING" rule as the scope: the machine when there is one, else the
            // shop, and NOTHING when there is neither.
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $eq) => $eq
                    ->whereNotNull('facility_work_orders.equipment_id')
                    ->whereColumn('prior.equipment_id', 'facility_work_orders.equipment_id'))
                ->orWhere(fn (Builder $unit) => $unit
                    ->whereNull('facility_work_orders.equipment_id')
                    ->whereNotNull('facility_work_orders.unit_id')
                    ->whereNull('prior.equipment_id')
                    ->whereColumn('prior.unit_id', 'facility_work_orders.unit_id')))
            ->whereColumn('prior.created_at', '<', 'facility_work_orders.created_at')
            ->whereRaw("prior.created_at >= {$cutoff}"),
        ]);
    }

    /** How many times this same thing was already worked on inside the window. */
    public function priorVisitCount(?int $days = null): int
    {
        // A list that used `withPriorVisitCount()` already paid for this; re-querying per row is
        // the N+1 that scope exists to remove. Only honoured for the DEFAULT window, because a
        // caller asking for a different one is asking a different question.
        if ($days === null && $this->prior_visit_count !== null) {
            return (int) $this->prior_visit_count;
        }

        return $this->trade_id === null
            ? 0
            : static::query()->repeatsOf($this, $days)->count();
    }

    /**
     * Is this job a repeat — somebody has been here for the same thing already?
     *
     * A follow-up is never a repeat: see {@see scopeRepeatsOf}.
     */
    public function isRepeatVisit(?int $days = null): bool
    {
        return $this->parent_work_order_id === null && $this->priorVisitCount($days) > 0;
    }

    /** Hours reported against this job. {@see FacilityWorkOrderLabour} */
    public function labour(): HasMany
    {
        return $this->hasMany(FacilityWorkOrderLabour::class);
    }

    /** Contractor invoices raised against this job — the service bucket. */
    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    /** Direct/petty-cash costs booked to this job — the service bucket's other road. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * **The single source of truth for what this job cost.**
     *
     * Written the way `Invoice::recomputeTotals()` is, and for the same reason: several independent
     * channels change the number, so exactly one method may compute it and every channel calls it.
     * Never set an `act_*` column anywhere else.
     *
     * THREE CHANNELS, and adding a fourth means adding it here AND wiring its model events:
     *
     *   labour   — `facility_work_order_labour`, hours x the craft rate frozen at entry
     *   material — approved/recorded part draws (`facility_work_order_parts.value`)
     *   service  — vendor bills + expenses booked to this job
     *
     * **NET of tax, and net of any SLA penalty applied to the bill.** VAT is recoverable and is not
     * a cost of the job; a penalty credited against a contractor's invoice genuinely reduces what
     * the work cost us, and `SlaPenaltyJournalizer` already credits the same expense account, so
     * taking it off here keeps this figure and the ledger telling the same story.
     *
     * **A cancelled document costs nothing** — excluded, exactly as `VendorBill::recompute()`
     * excludes a voided payment.
     *
     * This posts NOTHING. See the migration docblock: the money is already in the ledger through
     * three other documents, and these columns are a management dimension over it.
     */
    public function recomputeCosts(): void
    {
        $labour = $this->labour()
            ->selectRaw('coalesce(sum(hours), 0) as h, coalesce(sum(cost), 0) as c')
            ->first();

        $this->act_labour_hours = round((float) ($labour->h ?? 0), 2);
        $this->act_labour_cost = round((float) ($labour->c ?? 0), 2);

        // Only a part that actually left the store (or was recorded as bought for the job) is a
        // cost. A `pending` request is a proposal and a `rejected` one never happened.
        $this->act_material_cost = round((float) $this->parts()
            ->whereIn('status', [FacilityWorkOrderPart::STATUS_APPROVED, FacilityWorkOrderPart::STATUS_RECORDED])
            ->sum('value'), 2);

        $bills = round((float) $this->vendorBills()
            ->where('status', '!=', 'cancelled')
            ->selectRaw('coalesce(sum(subtotal - coalesce(penalty_applied_amount, 0)), 0) as net')
            ->value('net'), 2);

        $expenses = round((float) $this->expenses()
            ->where('status', '!=', 'cancelled')
            ->sum('amount'), 2);

        $this->act_service_cost = round($bills + $expenses, 2);

        $this->act_total_cost = round(
            (float) $this->act_labour_cost + (float) $this->act_material_cost + (float) $this->act_service_cost,
            2
        );

        $this->deriveEstimatedTotal();

        // saveQuietly: a derivation, not an operator action. Logging it would bury the change
        // somebody actually made under a cost row nobody typed.
        $this->saveQuietly();
    }

    /**
     * The planned total, from its parts.
     *
     * Derived for the same reason the actual one is: an operator who estimated two of three buckets
     * should not also have to add them up — and a stored total nothing re-derives is a second truth
     * about the same money.
     *
     * **Called from `saving` as well as from `recomputeCosts()`, and that is the whole point.** The
     * cost channels are what call `recomputeCosts()`, and none of them touches an estimate — so
     * editing `est_service_cost` on the form left `est_total_cost` at whatever it had been, and
     * `costVariance()` (the number an operator acts on) was computed from the stale figure.
     * Measured on the live database, not theorised.
     */
    private function deriveEstimatedTotal(): void
    {
        $stated = array_filter(
            [$this->est_labour_cost, $this->est_material_cost, $this->est_service_cost],
            fn ($v) => $v !== null,
        );

        $this->est_total_cost = $stated === []
            ? null                                   // nobody estimated anything; NOT zero
            : round(array_sum(array_map('floatval', $stated)), 2);
    }

    /**
     * Planned minus actual on the total, or null when nothing was planned.
     *
     * The number an operator can act on: a job estimated at 4 hours that consumed 14 is the
     * finding, and one showing only "14" is a figure nobody can do anything with.
     */
    public function costVariance(): ?float
    {
        return $this->est_total_cost === null
            ? null
            : round((float) $this->est_total_cost - (float) $this->act_total_cost, 2);
    }

    /** التخصص — what kind of work this is. See {@see Trade}. */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
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

    /** The zone's display name, null-safe — for the area-routing notification copy. */
    public function areaName(): ?string
    {
        // Key off the FK: a set area_id guarantees a row, but the zone may be soft-deleted (the
        // default relation excludes trashed), so the resolved model can still be null.
        if ($this->area_id === null) {
            return null;
        }

        /** @var Area|null $area */
        $area = $this->area;

        return $area?->name;
    }

    /** The unit's code, null-safe — for the area-routing notification copy (Larastan mistypes the BelongsTo). */
    public function unitCode(): ?string
    {
        /** @var Unit|null $unit */
        $unit = $this->unit;

        return $unit?->code;
    }

    /**
     * The machine this job is against (FR-PPM-03) — copied from the plan when raised, or
     * set directly on an ad-hoc order. Carried here rather than read through the plan
     * because an order outlives its plan (nullOnDelete) and ad-hoc orders have none.
     *
     * @return BelongsTo<Equipment, $this>
     */
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

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** FR-CM-03 — the in-house technician on the job (internal CM). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * FR-CM-01/USR-06 — the tenant request that reported the fault this job fixes, if any.
     * A tenant reported a problem; this is the facility work raised to resolve it (module 11 → 26).
     */
    public function tenantRequest(): BelongsTo
    {
        return $this->belongsTo(TenantRequest::class, 'tenant_request_id');
    }

    /** FR-CM-01 — the failed check that triggered this CM, if any. */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(FacilityWorkOrderItem::class, 'source_item_id');
    }

    /** FR-CM-15 — the order this one follows up on (its fix was incomplete). */
    public function parentWorkOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_work_order_id');
    }

    /** FR-CM-09/10/11 — spare parts on this job, internal draws and outside purchases. */
    public function parts(): HasMany
    {
        return $this->hasMany(FacilityWorkOrderPart::class);
    }

    /** What the parts actually cost this job — a rejected draw cost nothing. */
    public function partsCost(): float
    {
        return round((float) $this->parts()->counted()->sum('value'), 2);
    }

    /** FR-CM-08 — the SLA penalty assessed against the vendor, if any. */
    public function penalty(): HasOne
    {
        return $this->hasOne(SlaPenalty::class);
    }

    /** FR-CM-15 — follow-ups raised because this order's fix was incomplete. */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_work_order_id');
    }

    /** FR-CM-12/13 — who ruled on the cause. A claim needs a name against it. */
    public function faultRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fault_recorded_by_user_id');
    }

    /** Has anyone ruled on what caused this yet? */
    public function faultIsAttributed(): bool
    {
        return $this->fault_party !== null;
    }

    /** FR-CM-13 — is the tenant on the hook for this repair? */
    public function tenantBearsCost(): bool
    {
        return $this->cost_bearer === self::BEARER_TENANT;
    }

    /**
     * The tenant who would bear the cost, if any (FR-CM-13).
     *
     * A work order carries `asset_id` + a NULLABLE `unit_id` and **no tenant_id** — a common-area
     * chiller has no occupier, which is exactly why CM lives here and not in module 11. So the
     * tenant is resolved through the unit's active lease, and a job with no unit can never have
     * one. Resolved live rather than stored: the answer must be "who occupies that unit", not
     * "who occupied it when someone happened to click".
     */
    public function bearingTenant(): ?Tenant
    {
        return $this->unit?->currentTenant();
    }

    public function isCorrective(): bool
    {
        return $this->work_order_type === self::TYPE_CM;
    }

    public function scopeCorrective(Builder $query): Builder
    {
        return $query->where('work_order_type', self::TYPE_CM);
    }

    public function scopePreventive(Builder $query): Builder
    {
        return $query->where('work_order_type', self::TYPE_PPM);
    }

    /**
     * Photographs and paperwork for the job — `useDisk('local')`, i.e. PRIVATE.
     *
     * Explicit, because medialibrary's default is `env('MEDIA_DISK', 'public')` and therefore
     * fail-open: forgetting to declare a disk is indistinguishable from choosing the webroot,
     * which is how signed leases and tenant tax cards once came to be served from guessable URLs.
     * A work-order photo shows the inside of a tenant's shop, the state of plant, and sometimes a
     * person — none of it belongs on an unauthenticated URL.
     *
     * ONE collection, not a before/after pair. Which photograph is "before" is a judgement the
     * engineer makes at the moment of upload and frequently gets wrong, and a mislabelled pair is
     * worse evidence than an unlabelled set — it asserts something false about a job someone may
     * later be billed for. The order of upload and the file's own timestamp carry the sequence.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence')->useDisk('local');
    }

    /** Does this job carry any evidence at all? The predicate the completion gate reads. */
    public function hasEvidence(): bool
    {
        return $this->getMedia('evidence')->isNotEmpty();
    }

    public function items(): HasMany
    {
        return $this->hasMany(FacilityWorkOrderItem::class);
    }

    /**
     * Checklist items the engineer marked as failed. These are what FR-CM-01 raises
     * corrective maintenance from — a failed PPM check is the canonical CM trigger.
     */
    public function failedItems(): HasMany
    {
        return $this->items()->failed();
    }

    /** FR-PPM-07 — does every checklist item carry an outcome yet? */
    public function checklistIsComplete(): bool
    {
        return ! $this->items()->pending()->exists();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /**
     * Stamp both SLA clocks on a corrective order. Idempotent: only ever FILLS a null.
     *
     * **Why the resolution deadline is stamped here and not only on acceptance.**
     * `target_resolution_at` used to be written in exactly one place — the manual
     * `open → in_progress` hop — and `open → done` is a legal transition. So an external job could
     * be created, worked for three weeks and closed with the target still null; `isSlaBreached()`
     * requires a non-null target, so the scan, the penalty gate, the filter and the dashboard all
     * skipped it. Not clicking Start silently waived the vendor's penalty.
     *
     * The rule, in one sentence: **a job has `resolve_hours` from the moment it was accepted, or
     * from the moment it should have been — whichever came first.** At creation that is the
     * response deadline, which is why a deadline now always exists. `FacilityWorkOrderService`
     * tightens it to the real acceptance time when the job is picked up on time. Accepting LATE
     * cannot push it out: ignoring a job must not buy more time to finish it.
     *
     * FR-CM-07 is untouched by this — an engineer who accepts within the response window still gets
     * their full resolution window from that moment, and is never charged for queue time. Queue
     * time is what the response clock is for.
     *
     * Preventive rounds have neither clock: they are scheduled work with a `scheduled_for`, not a
     * response-and-repair obligation, and every SLA surface in the module filters `->corrective()`.
     */
    public function stampSlaClocks(): void
    {
        if (! $this->isCorrective()) {
            return;
        }

        $priority = (string) ($this->priority ?? 'medium');

        if ($this->target_response_at === null) {
            $this->target_response_at = ($this->created_at ?? now())
                ->copy()
                ->addHours(SlaResolver::respondHoursFor($this->asset_id, $priority));
        }

        if ($this->target_resolution_at === null) {
            $this->target_resolution_at = $this->target_response_at
                ->copy()
                ->addHours(SlaResolver::hoursFor($this->asset_id, $priority));
        }
    }

    /**
     * Nobody took the job on in time. Independent of the resolution clock: a job can be responded
     * to late and still finish inside its resolution window, and both facts matter — one is the
     * queue's, the other the engineer's or the contractor's.
     *
     * Measured to acceptance, or to now while it is still unanswered. A job that reached a terminal
     * state without ever being acknowledged was never responded to at all, so it is measured to the
     * moment it closed rather than growing forever in the archive — the same reasoning as
     * `hoursOverSla()`.
     */
    public function isResponseBreached(): bool
    {
        if ($this->target_response_at === null) {
            return false;
        }

        return $this->responseEndedAt()->greaterThan($this->target_response_at);
    }

    /** Whole hours past the response target; 0 when answered in time. */
    public function hoursOverResponseSla(): int
    {
        if (! $this->isResponseBreached()) {
            return 0;
        }

        return (int) abs($this->target_response_at->diffInHours($this->responseEndedAt()));
    }

    /** When the response clock stopped: acceptance, else closure, else it is still running. */
    private function responseEndedAt(): CarbonInterface
    {
        return $this->acknowledged_at ?? $this->completed_at ?? now();
    }

    /**
     * Unanswered past its response target and still open — the scan/filter/dashboard set.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeResponseBreached(Builder $query): Builder
    {
        return $query->corrective()
            ->whereNull('acknowledged_at')
            ->whereNotIn('status', self::TERMINAL)
            ->whereNotNull('target_response_at')
            ->where('target_response_at', '<', now());
    }

    /**
     * Past its SLA target and still open (FR-CM-08). Derived, not stored — an order with no
     * target (never accepted, or preventive) is never overdue.
     */
    public function isOverdue(): bool
    {
        return $this->target_resolution_at !== null
            && ! $this->isTerminal()
            && $this->target_resolution_at->isPast();
    }

    /**
     * Whole hours past the SLA target; 0 when never late.
     *
     * Lateness stops at completion, not at "now". Measuring to now would keep a finished
     * job's overrun growing forever — and since this is what an accruing penalty is
     * computed from, a job closed two hours late would quietly bill more every day it sat
     * in the archive.
     */
    public function hoursOverSla(): int
    {
        if ($this->target_resolution_at === null) {
            return 0;
        }

        $end = $this->completed_at ?? now();

        if ($end->lessThanOrEqualTo($this->target_resolution_at)) {
            return 0;
        }

        // Whole hours over, truncated — the INFORMATIONAL figure (frozen onto the penalty row +
        // shown in the UI). The money must NOT gate on this: `(int)` of a sub-hour overrun is 0,
        // yet the job IS late (see isSlaBreached / daysOverSla, which the penalty uses instead).
        return (int) abs($this->target_resolution_at->diffInHours($end));
    }

    /**
     * Is the job actually past its SLA — the precise gate the penalty uses (any positive
     * lateness, even sub-hour). `hoursOverSla()` truncates to 0 below an hour, so gating a
     * penalty on `hoursOverSla() > 0` let a job minutes late escape assessment entirely; once
     * terminal, the open-only scan never revisited it → the vendor escaped it forever.
     */
    public function isSlaBreached(): bool
    {
        return $this->target_resolution_at !== null
            && ($this->completed_at ?? now())->greaterThan($this->target_resolution_at);
    }

    /**
     * Whole days over the SLA, rounding a partial day UP ("part of a day counts as a whole day",
     * §7g) — computed from the TRUE elapsed time, not truncated hours. 30 min late → 1 day;
     * 48h40m → 3 days (truncating to 48h wrongly gave 2). Zero when not breached.
     */
    public function daysOverSla(): int
    {
        if (! $this->isSlaBreached()) {
            return 0;
        }

        $end = $this->completed_at ?? now();

        return (int) ceil($this->target_resolution_at->diffInSeconds($end) / 86400);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL);
    }

    /**
     * `WO-{asset}-{YYYYMM}-{n}` for preventive, `CM-…` for corrective — an engineer can tell
     * a scheduled visit from a fault report by the reference alone (mirrors module 11's
     * per-type reference prefixes).
     */
    public static function generateReference(string $assetCode = 'GEN', ?\DateTimeInterface $date = null, string $type = self::TYPE_PPM): string
    {
        $date = $date ? Carbon::instance($date) : now();
        $prefix = sprintf('%s-%s-%s-', $type === self::TYPE_CM ? 'CM' : 'WO', $assetCode, $date->format('Ym'));

        $last = static::withTrashed()->where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        // Bump until free — the bare max+1 is race-prone under concurrent creates
        // (mirrors TenantRequest::generateReference); the DB unique index is the backstop.
        $candidate = $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            $candidate = $prefix.str_pad((string) ++$next, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    /** Bell the assigned technician (N2). Throwable-guarded — a notify hiccup never breaks the write. */
    private static function notifyAssignee(self $order): void
    {
        try {
            User::find((int) $order->assigned_to_user_id)
                ?->notify(new WorkOrderAssignedNotification($order));
        } catch (\Throwable $e) {
            Log::warning('Work-order assigned notification failed', ['error' => $e->getMessage()]);
        }
    }

    protected static function booted(): void
    {
        // The planned total is a function of its three parts, so it is derived on EVERY save.
        // `recomputeCosts()` is called by the COST channels — labour, parts, bills — and none of
        // them touches an estimate, so without this an operator editing `est_service_cost` left
        // the stored total at its previous value and `costVariance()` reported against a stale
        // figure. `saveQuietly()` does not fire this, which is exactly right: the recompute path
        // calls the derivation directly and cannot loop.
        static::saving(fn (self $order) => $order->deriveEstimatedTotal());

        static::creating(function (self $order) {
            if (empty($order->reference)) {
                $order->reference = static::generateReference(
                    $order->asset?->code ?: 'GEN',
                    $order->scheduled_for,
                    $order->work_order_type ?? self::TYPE_PPM,
                );
            }

            // Area routing (module 30 → 11): a work order lives in a facility zone, just like the
            // request it may come from. Inherit the zone when it wasn't set explicitly — first from
            // the linked tenant request (which already derived it from its unit), then from the
            // order's own unit. PPM orders arrive with the plan's area already set, so this only
            // FILLS a null and never overrides an explicit zone. Model-level so every path (the PPM
            // sweep, RaiseCorrectiveWorkOrderService, the Filament form, the factory) inherits it —
            // the same reason TenantRequest derives its area in the model, not a service.
            if ($order->area_id === null) {
                $derived = null;
                if ($order->tenant_request_id !== null) {
                    $derived = TenantRequest::whereKey($order->tenant_request_id)->value('area_id');
                }
                if ($derived === null && $order->unit_id !== null) {
                    $derived = Unit::whereKey($order->unit_id)->value('area_id');
                }
                $order->area_id = $derived === null ? null : (int) $derived;
            }

            $order->stampSlaClocks();
        });

        static::saving(function (self $order) {
            if (! in_array($order->work_order_type, self::TYPES, true)) {
                throw new InvalidArgumentException(
                    "Unknown work_order_type '{$order->work_order_type}'; expected one of: ".implode(', ', self::TYPES).'.'
                );
            }

            // Compliance gate (strengthen #5): never dispatch a non-dispatchable vendor
            // (blacklisted/inactive, or its insurance/COI has lapsed). Only on assignment or
            // reassignment — an existing order whose vendor's COI expires later isn't retroactively
            // broken. This is the single server-side choke point for every write path (Filament
            // form, RaiseCorrectiveWorkOrderService, factory); the pickers filter to Vendor
            // ::assignable() too, but a client can post any vendor_id, so this is the real gate.
            if ($order->isDirty('vendor_id') && $order->vendor_id !== null) {
                $vendor = Vendor::find($order->vendor_id);
                if ($vendor !== null && ! $vendor->isDispatchable()) {
                    throw new \DomainException(
                        "Vendor '{$vendor->name}' cannot be dispatched: it is blacklisted/inactive or its insurance (COI) has lapsed."
                    );
                }
            }

            if ($order->work_order_type !== self::TYPE_CM) {
                return;
            }

            // ---- CM-only rules (FR-CM-02/03/04) ----------------------------------

            if (! in_array($order->execution_type, self::EXECUTION_TYPES, true)) {
                throw new InvalidArgumentException(
                    "A corrective work order must be classified internal or external (FR-CM-02); got '{$order->execution_type}'."
                );
            }

            if (blank($order->description)) {
                throw new InvalidArgumentException('A corrective work order requires a description (FR-CM-04).');
            }

            // FR-CM-02/03 as a real XOR. Module 11 allows a request to carry BOTH a staff
            // assignee and a vendor at once, which is exactly why its assignment could never
            // serve as the internal-vs-external discriminator (doc 11 §"Gotchas" says so
            // outright). Repeating that here would make execution_type decorative: the
            // classification has to constrain who is actually on the job.
            if ($order->execution_type === self::EXECUTION_INTERNAL && $order->vendor_id !== null) {
                throw new InvalidArgumentException('An internal corrective work order is handled in-house; it cannot also name a vendor.');
            }

            if ($order->execution_type === self::EXECUTION_EXTERNAL && $order->assigned_to_user_id !== null) {
                throw new InvalidArgumentException('An external corrective work order is handled by the vendor; it cannot also name an in-house technician.');
            }
        });

        // Ping the newly-assigned technician — AssignmentScope (FR-USR-04) hides every OTHER
        // work order from an operations user, so without this they never learn one landed on
        // them. Split created/updated hooks (NOT `saved` + wasRecentlyCreated, which stays true
        // on the instance for later saves and would re-ping on any subsequent edit). Generated
        // PPM orders carry no assignee, so this is silent for them (N3 covers those).
        static::created(function (self $order) {
            if ($order->assigned_to_user_id !== null) {
                self::notifyAssignee($order);
            }

            // Area routing (module 30 → 11): tell the zone's supervisors a job landed in their area
            // — the work-order half of the request routing. Notify, NOT assign: work-order ownership
            // follows the plan / the CM internal-vs-external XOR, not the zone. Fail-safe + a no-op
            // when there's no zone or no supervisors (contained inside the service).
            //
            // Fires INSIDE the PPM/CM create transaction (like notifyAssignee above). Safe because
            // AreaWorkOrderRaisedNotification is synchronous and database-only in practice — a
            // rolled-back generation discards the row with the txn, and `push` no-ops for admin Users
            // (no device tokens). If it is ever made ShouldQueue, or supervisors become push-capable,
            // move this to a post-commit fan-out (as GeneratePreventiveWorkOrdersService does for
            // WorkOrderRaisedNotification) so a rollback can't strand an external side-effect.
            app(NotifyAreaSupervisorsService::class)->notifyWorkOrder($order);
        });

        static::updated(function (self $order) {
            if ($order->wasChanged('assigned_to_user_id') && $order->assigned_to_user_id !== null) {
                self::notifyAssignee($order);
            }
        });
    }
}
