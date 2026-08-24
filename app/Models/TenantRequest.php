<?php

namespace App\Models;

use App\Enums\TenantRequestType;
use App\Models\Concerns\HasSearchText;
use App\Services\NotifyAreaSupervisorsService;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use App\Support\SlaResolver;
use App\Support\WorkingCalendar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[DeletionAllowed(reason: 'operational: terminal states are already immutable')]
#[PropertyOwned(via: 'unit')]
class TenantRequest extends Model implements HasMedia
{
    use HasFactory, HasSearchText, InteractsWithMedia, LogsActivity, SoftDeletes;

    // Shared with module 26 through `SlaResolver`, which both modules already use for SLA HOURS.
    //
    // Unlike `FacilityWorkOrder`, `sla_clock` IS fillable here, and that difference is deliberate.
    // A work order's clock is only ever written by its service, so guarding the attribute costs
    // nothing. A tenant request has two intake roads, and the admin one is a Filament
    // `CreateRecord` — which mass-assigns the mutated form data, and would silently DROP a
    // non-fillable key. The freeze would then be missing on exactly one of the two roads, with no
    // error to say so. Both writers set the value themselves instead: the service builds an
    // explicit whitelist (it never spreads the client payload) and the page force-sets
    // `$data['sla_clock']` after resolving it, so a crafted Livewire submit cannot choose its own
    // deadline semantics. A third intake road must do one of those two things.
    public const SLA_CLOCK_CALENDAR = SlaResolver::CLOCK_CALENDAR;

    public const SLA_CLOCK_WORKING = SlaResolver::CLOCK_WORKING;

    /** @var array<int, string> */
    public const SLA_CLOCKS = SlaResolver::CLOCKS;

    public const STATUSES = [
        'submitted',
        'acknowledged',
        'in_progress',
        'awaiting_tenant',
        'resolved',
        'closed',
        'cancelled',
    ];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    // NOTE: there is deliberately no `CATEGORIES` const here. There was one — the seven
    // MAINTENANCE sub-categories, left over from when maintenance was the only kind of request —
    // and it had no readers at all by the time it was removed. Its danger was that it looked like
    // the answer: a sub-category is scoped to its TYPE (`access` has `keys_cards`/`parking`/…,
    // `document` has `lease_copy`/…, and `inquiry`/`billing` PROHIBIT one), so any single flat list
    // is wrong for six of the eight types. The one answer is
    // {@see \App\Enums\TenantRequestType::subcategories()}.

    public const OPEN_STATUSES = ['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant'];

    /**
     * Reference and subject, plus the caller's name and number for a request phoned in
     * by someone who is not a portal user — `caller_name` is the only identity those carry.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->title,
            $this->description,
            $this->caller_name,
            $this->caller_phone,
            self::digitsOf($this->caller_phone),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'tenant_request', alsoLog: ['confirmed_at']);
    }

    protected $fillable = [
        'reference',
        'tenant_id',
        'unit_id',
        'area_id',
        'caller_name',
        'caller_phone',
        'caller_notes',
        'lease_id',
        'request_type',
        'assigned_to',
        'assigned_to_vendor_id',
        'department_id',
        'status',
        'priority',
        'category',
        'channel',
        'title',
        'description',
        'resolution_notes',
        'decision',
        'decision_reason',
        'decided_at',
        'decided_by',
        'submitted_at',
        // Fillable because BOTH intake roads write it through mass assignment — the service's
        // `create()` and the admin page's `mutateFormDataBeforeCreate`. No form offers it: an
        // operator changes the POLICY, never one request's clock.
        'sla_clock',
        'acknowledged_at',
        'resolved_at',
        'closed_at',
        'target_resolution_at',
        'scheduled_from',
        'scheduled_to',
        'valid_from',
        'valid_to',
        'sla_breach_notified_at',
        'csat_rating',
        'csat_comment',
    ];

    protected $casts = [
        'request_type' => TenantRequestType::class,
        'csat_rating' => 'integer',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'target_resolution_at' => 'datetime',
        'scheduled_from' => 'datetime',
        'scheduled_to' => 'datetime',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'sla_breach_notified_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    /** The only channel a tenant raises for themselves; every other channel is staff-logged intake. */
    public const SELF_SERVICE_CHANNEL = 'portal';

    protected static function booted(): void
    {
        // FR-REQ intake invariant (Phase 9a), enforced in the model so admin + portal + API all
        // inherit it. A request may omit its tenant ONLY when a staff channel logged it for an
        // unregistered caller — and even then it must record WHO reported it. A tenant-less portal
        // request is a contradiction (the portal always acts as a known, authenticated tenant).
        static::saving(function (self $request) {
            if ($request->tenant_id !== null) {
                return;
            }

            if ($request->channel === self::SELF_SERVICE_CHANNEL) {
                throw new \DomainException(__('admin.tenant_requests.errors.portal_needs_tenant'));
            }

            if (blank($request->caller_name)) {
                throw new \DomainException(__('admin.tenant_requests.errors.caller_or_tenant_required'));
            }
        });

        // FR-REQ-14 permit validity window. A permit carries a validity period (valid_from/to);
        // enforced in the model so admin + portal + API all inherit it. Only the ORDERING is an
        // invariant here — if BOTH dates are set, valid_to must not predate valid_from. The dates
        // are NOT hard-required at this layer (the permit form requires them for its type), so
        // non-permit rows and partial data never blow up.
        //
        // (This comment used to end "There is NO approval step." That stopped being true on
        // 2026-08-15: a permit is a question, and `decision` now records the answer. See the
        // migration `a_request_records_the_answer_it_was_given` for why the original call was
        // reversed.)
        static::saving(function (self $request) {
            if ($request->valid_from === null || $request->valid_to === null) {
                return;
            }

            if ($request->valid_to->lt($request->valid_from)) {
                throw new \DomainException(__('admin.tenant_requests.errors.permit_validity_order'));
            }
        });

        // A CLOSED / CANCELLED request is terminal — its descriptive + routing fields freeze at the
        // model layer (mirrors Lease/Invoice; closes the API/console/Edit-form path behind the UI,
        // which the generic admin Edit page otherwise left open). Keyed on the ORIGINAL status so the
        // transition INTO closed — which sets resolution_notes — is allowed, and post-close CSAT
        // (csat_rating/csat_comment via rate(); a closed request stays rateable) + soft-delete/restore
        // stay allowed. Terminal statuses have no outgoing transition anyway (the service matrix).
        // Pre-go-live sweep (terminal-state) — the money-free peer of the VendorBill immutability gap.
        static::updating(function (self $request) {
            if (! in_array($request->getOriginal('status'), ['closed', 'cancelled'], true)) {
                return;
            }
            $frozen = [
                'request_type', 'title', 'description', 'priority', 'category',
                'assigned_to', 'assigned_to_vendor_id', 'department_id', 'area_id',
                'unit_id', 'lease_id', 'tenant_id',
                // `sla_clock` freezes with the deadline it measures. Freezing what the target IS
                // while leaving what it is measured AGAINST mutable is half a rule.
                'target_resolution_at', 'sla_clock',
                'scheduled_from', 'scheduled_to', 'valid_from', 'valid_to',
                'resolution_notes',
                // The answer freezes with everything else. A tenant has been told, and may have
                // shown the permit at a gate; quietly flipping an approval to a rejection after
                // the fact is not a correction, it is a rewrite. Re-open the request to change it.
                // (Setting it on the `resolved` hop is unaffected — resolved is not terminal.)
                'decision', 'decision_reason',
            ];
            foreach ($frozen as $field) {
                if ($request->isDirty($field)) {
                    throw new \DomainException(__('admin.tenant_requests.errors.terminal_immutable'));
                }
            }
        });

        // Area routing (module 30 → 11), derived in the model so admin + portal + API all inherit
        // it. A request lives in its unit's facility zone: if no area was set explicitly, inherit
        // the unit's. An explicitly-set area_id is never overridden (a caller may target a zone
        // directly). Kept cheap — a single-column lookup, only when area_id is null. (unit_id is
        // NOT NULL, so the lookup always has a key; a unit with no zone just yields null.)
        static::creating(function (self $request) {
            // Derive the zone from the unit if it wasn't set explicitly.
            if ($request->area_id === null) {
                $request->area_id = Unit::whereKey($request->unit_id)->value('area_id');
            }

            // FR-REQ-08 automatic assignment: route the request to its zone's supervisor — but ONLY
            // when the zone has EXACTLY ONE supervisor, which is the unambiguous "designated
            // supervisor" the FRD means ("each area has a designated supervisor"). A zone with
            // several supervisors stays unassigned: they're all notified on `created` and a
            // coordinator picks the owner (manual assignment, FR-REQ-07). Never overrides an
            // explicit assignee. Enforced in the model, so admin + portal + API all inherit it.
            if ($request->assigned_to === null && $request->area_id !== null) {
                /** @var Area|null $area */
                $area = Area::find($request->area_id);
                if ($area !== null) {
                    $supervisorIds = $area->supervisors()->pluck('users.id')->all();
                    if (count($supervisorIds) === 1) {
                        $request->assigned_to = (int) $supervisorIds[0];
                    }
                }
            }
        });

        // Notify the zone's supervisors that a request landed in their area (routing, not
        // assignment). Fires from the model `created` event — the single hook every create path
        // (admin Filament, portal, mobile API) passes through — so no channel can skip it. A no-op
        // when there's no area or no supervisors; failures are contained inside the service.
        static::created(function (self $request) {
            app(NotifyAreaSupervisorsService::class)->notify($request);
        });
    }

    /** Which person at the tenant accepted the resolution. {@see confirmedByTenant} */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'confirmed_by_tenant_user_id');
    }

    /**
     * Did the TENANT accept this, or did the operator or the timer close it?
     *
     * The distinction is the whole point of storing `confirmed_at`: `requests:auto-close` takes
     * silence as consent after `config('requests.auto_close_after_days')`, which is the right
     * default — chasing a retailer for a click is how a queue of "resolved" requests never closes —
     * but a close nobody confirmed must not LOOK like one somebody did.
     */
    public function confirmedByTenant(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Who reported it: the registered tenant's name, or — for a caller-only intake — the caller. */
    public function reportedByName(): ?string
    {
        // Key off tenant_id: the FK guarantees a tenant row whenever it is set; a caller-only row
        // (tenant_id null) reads the caller name instead.
        if ($this->tenant_id === null) {
            return $this->caller_name;
        }

        /** @var Tenant $tenant */
        $tenant = $this->tenant;

        return $tenant->name;
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The unit's code, null-safe — for notification copy (Larastan mistypes the BelongsTo). */
    public function unitCode(): ?string
    {
        /** @var Unit|null $unit */
        $unit = $this->unit;

        return $unit?->code;
    }

    /** The facility zone this request sits in (module 30) — inherited from the unit on intake. */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** The zone's display name, null-safe — for the table/notification copy. */
    public function areaName(): ?string
    {
        // Key off the FK: a set area_id guarantees a row, but the zone may be soft-deleted (the
        // default relation excludes trashed), so the local $area can still be null.
        if ($this->area_id === null) {
            return null;
        }

        /** @var Area|null $area */
        $area = $this->area;

        return $area?->name;
    }

    /**
     * FR-USR-06 — the facility work orders raised to fix this request (module 26).
     *
     * A request can spawn more than one (a flood needs plumbing AND electrical). The existence of
     * any one is the "linked work order" that satisfies FR-USR-06's completion evidence.
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class, 'tenant_request_id');
    }

    /** Is there facility work on record for this request? (FR-USR-06 evidence.) */
    public function hasLinkedWorkOrder(): bool
    {
        return $this->workOrders()->exists();
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'assigned_to_vendor_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TenantRequestComment::class)->orderBy('created_at');
    }

    /** Stock consumed against this request (inventory module 22, Phase 2). */
    public function stockConsumptions(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source')
            ->where('type', 'consumption')
            ->latest('moved_on');
    }

    /**
     * Attachments live on a PRIVATE disk (not web-accessible). They're tenant
     * photos/documents and must never be reachable via a guessable public URL —
     * they're streamed only through authenticated, tenant-scoped endpoints (the
     * mobile API controller + the authed admin panel). See hardening backlog H2.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->useDisk('local');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Terminal (closed/cancelled) work-orders are immutable — FR REQ-3.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['closed', 'cancelled'], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->target_resolution_at
            && $this->target_resolution_at->isPast();
    }

    /**
     * How far past its deadline this request is, ON THE CLOCK IT WAS PROMISED (EG-38).
     *
     * The bell entry quotes this number to an operator, so it has to be measured the same way the
     * deadline was set. A request promised on the working clock and breached on a Thursday evening
     * is not "62 hours late" by Sunday morning — the mall was shut for two of those days, and a
     * figure that counts them tells the operator the failure is fifteen times worse than it is.
     * There is no money on this one (module 26's `daysOverSla()` drives a GL penalty; this drives a
     * sentence), which is exactly why it needs saying once here rather than at each reader.
     *
     * Zero only when the request is NOT breached, so a caller can print it without a guard.
     */
    public function hoursOverSla(): int
    {
        if ($this->target_resolution_at === null) {
            return 0;
        }

        // Lateness stops when the work was declared done, not at "now" — the rule
        // `FacilityWorkOrder::hoursOverSla()` states and this did not, so a resolved request's
        // overrun kept growing in the archive. Only the open-only scan reads it today, which is
        // why it was latent; it is presented on the model as THE definition, so it has to hold for
        // any reader.
        $end = $this->resolved_at ?? $this->closed_at ?? now();

        if ($end->lessThanOrEqualTo($this->target_resolution_at)) {
            return 0;
        }

        if ($this->sla_clock === self::SLA_CLOCK_WORKING) {
            // Through the unit, because that is where this model's property lives —
            // `#[PropertyOwned(via: 'unit')]`, not a column of its own. A null unit falls back to
            // the portfolio calendar, which is the same thing the service did when it set the
            // deadline, so the two measures stay commensurate.
            // Floored at 1 for a request that IS breached, exactly as `daysOverSla()` is and for
            // the same reason: an overrun that fell entirely across a weekend contains no working
            // time, and reporting "0 h past its target resolution" in the breach bell states that
            // nothing is wrong on a request that is late. A breach is a breach. (On the CALENDAR
            // branch below, 0 genuinely means "less than an hour late" — a different claim, left
            // alone.)
            return max(1, (int) floor(
                WorkingCalendar::workingSecondsBetween(
                    $this->target_resolution_at, $end, $this->unit?->asset_id
                ) / 3600
            ));
        }

        return (int) abs($this->target_resolution_at->diffInHours($end));
    }

    /**
     * Whether resolving this request has to state approved/rejected — i.e. whether it ASKED for
     * something. Delegates to the type so there is one answer, not one per surface.
     */
    public function requiresDecision(): bool
    {
        return ($this->request_type ?? TenantRequestType::default())->requiresDecision();
    }

    public function wasApproved(): bool
    {
        return $this->decision === 'approved';
    }

    public function wasRejected(): bool
    {
        return $this->decision === 'rejected';
    }

    /**
     * A request that should carry an answer, is finished, and has none.
     *
     * Only rows written before the decision column existed can be in this state — the service
     * refuses to create a new one. It is named rather than left implicit because **every reader
     * must render it as "we do not know", never as an approval**: inferring approval from a closed
     * ticket is the exact bug the column was added to end.
     */
    public function decisionUnknown(): bool
    {
        return $this->requiresDecision()
            && in_array($this->status, ['resolved', 'closed'], true)
            && $this->decision === null;
    }

    /**
     * Localised label for this request's type (Maintenance, Complaint, …). Used
     * as the `:type` placeholder in notification copy so a complaint never reads
     * "Maintenance …". Falls back to the maintenance label for legacy rows.
     */
    public function typeLabel(): string
    {
        return ($this->request_type ?? TenantRequestType::default())->label();
    }

    public static function generateReference(string $assetCode = 'AW', string $prefix = 'MR'): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;

        // Bump the suffix until the reference is free — the bare count is race-prone
        // under concurrent creates (mirrors the Invoice/Payment number helpers).
        $candidate = sprintf('%s-%s-%s-%04d', $prefix, $assetCode, $year, $count);
        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists() && $attempts < 50) {
            $candidate = sprintf('%s-%s-%s-%04d', $prefix, $assetCode, $year, ++$count);
            $attempts++;
        }

        return $candidate;
    }
}
