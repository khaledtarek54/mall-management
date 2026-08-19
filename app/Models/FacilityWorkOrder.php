<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Notifications\WorkOrderAssignedNotification;
use App\Services\NotifyAreaSupervisorsService;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use App\Support\SlaResolver;
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
            $this->category,
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
        'category',
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
        'job_value',
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
        'job_value' => 'decimal:2',
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
            ->logOnly(['service_plan_id', 'work_order_type', 'execution_type', 'asset_id', 'unit_id', 'area_id', 'equipment_id', 'title', 'category', 'status', 'priority', 'scheduled_for', 'acknowledged_at', 'target_response_at', 'target_resolution_at', 'completed_at', 'vendor_id', 'assigned_to_user_id', 'parent_work_order_id', 'tenant_request_id', 'fault_party', 'cost_bearer', 'fault_notes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('facility_work_order');
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
