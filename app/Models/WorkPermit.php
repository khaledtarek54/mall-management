<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Permit to work — the operator's written authorisation for hazardous physical work.
 *
 * **An EXTENSION, not a Yardi construct, and flagged as one.** Voyager is lease-administration
 * software and does not model this; the benchmark folder has zero hits for hot work, isolation or
 * permit-to-work. It follows the FM/CMMS standard — ServiceChannel, Facilio and Maximo all treat a
 * permit as core — and ordinary safety practice. The project's rule is to name the Voyager construct
 * or admit the invention, so: admitted.
 *
 * A contractor cutting or welding in a plant room, isolating a panel, or working above a trading
 * floor is a risk the operator carries whether or not anyone wrote it down. The industry control is
 * a permit: a named person authorises specific work, in a specific place, for a specific WINDOW,
 * under stated conditions — and somebody closes it out afterwards confirming the area was left safe.
 *
 * ## The two properties that make this a control rather than a form
 *
 * 1. **Time-bounded to the hour.** "Hot work permitted on Tuesday" is not a permit; "hot work
 *    permitted 09:00–13:00 Tuesday" is. A permit good for a whole day is one somebody uses at 19:00
 *    when the fire officer has gone home.
 * 2. **It must be CLOSED, and an expired-but-open permit is the finding.** Work authorised and never
 *    signed off means nobody confirmed the welding stopped and the area was checked. That is the
 *    state a safety audit looks for, which is why {@see scopeOverdueClosure} exists and why the
 *    nightly scan reports it.
 *
 * ## Deliberately separate from the tenant's fit-out permit
 *
 * `TenantRequestType::Permit` is a TENANT asking permission through the portal. This is the OPERATOR
 * authorising a contractor — often its own vendor, with no tenant involved. Folding them together
 * would make a safety control lease-shaped, which is the mistake that once keyed rentable items to
 * a lease.
 */
#[DeletableWhenUnused(blockedBy: [], instead: 'cancel the permit — a safety authorisation that was issued is a record an auditor may ask for, and deleting it removes the evidence that it was controlled')]
#[PropertyOwned]
class WorkPermit extends Model
{
    use AllocatesDocumentNumber, HasFactory, HasSearchText, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    public const TYPE_HOT_WORK = 'hot_work';

    public const TYPE_ELECTRICAL_ISOLATION = 'electrical_isolation';

    public const TYPE_WORKING_AT_HEIGHT = 'working_at_height';

    public const TYPE_CONFINED_SPACE = 'confined_space';

    public const TYPE_EXCAVATION = 'excavation';

    public const TYPE_GENERAL = 'general';

    public const TYPES = [
        self::TYPE_HOT_WORK, self::TYPE_ELECTRICAL_ISOLATION, self::TYPE_WORKING_AT_HEIGHT,
        self::TYPE_CONFINED_SPACE, self::TYPE_EXCAVATION, self::TYPE_GENERAL,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_CLOSED, self::STATUS_CANCELLED];

    /**
     * There is no `expired` status, deliberately.
     *
     * Expiry is a fact about the clock, not a decision anybody made, and a nightly sweep flipping
     * permits to `expired` would quietly close the audit question this register exists to ask: an
     * ISSUED permit whose window has passed and which nobody signed off is exactly the finding.
     * Derive it ({@see hasLapsed}), never store it — the same reasoning that keeps
     * `App\Support\ProjectedState` honest about which stored columns are functions of today.
     */
    public const TERMINAL = [self::STATUS_CLOSED, self::STATUS_CANCELLED];

    protected $fillable = [
        'asset_id', 'type', 'status', 'vendor_id', 'contractor_name', 'contractor_phone',
        'facility_work_order_id', 'unit_id', 'area_id', 'location', 'description', 'conditions',
        'valid_from', 'valid_to', 'issued_by_user_id', 'issued_at',
        'closed_by_user_id', 'closed_at', 'closure_notes',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'issued_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected static function booted(): void
    {
        static::creating(function (self $permit) {
            $permit->reference ??= $permit->allocateDocumentNumber(
                static::referencePrefix(),
                fn (): string => static::generateUniqueReference(),
            );
        });

        // A window that ends before it begins authorises nothing, and would make every
        // "is this permit live?" question answer no in a way that reads as a data problem rather
        // than a refusal. At the MODEL so an import or an API write is covered, not only the form.
        static::saving(function (self $permit) {
            if ($permit->valid_from !== null && $permit->valid_to !== null
                && Carbon::parse($permit->valid_from)->gte(Carbon::parse($permit->valid_to))) {
                throw new DomainException(__('admin.errors.work_permit_window_inverted'));
            }
        });

        // ── A LIVE AUTHORISATION IS NOT A DRAFT, and until now only the LIST said so ──────────
        //
        // The register hides its Edit shortcut the moment a permit is issued, under a comment
        // stating exactly this rule. That is a rendering decision: `EditWorkPermit` is the record
        // hub, it is reached by URL, and `canEdit()` went on answering true — so an issued or closed
        // permit was fully editable through its own form (SW-066). What a permit AUTHORISES — the
        // work, the place, the window, the conditions, who is doing it — is what the guard at the
        // door reads and what a manager acts on when the closure alert fires. Rewriting it after
        // issue changes an authorisation that people are already working under.
        //
        // **A DENYLIST of substance, never an allowlist of what the acts write.** `getDirty()` is
        // read after every `saving` hook — `HasSearchText` rewrites `search_text` on every save, and
        // `close()` writes three columns of its own — so an allowlist would have to enumerate every
        // derived column too, and refuses the day one is added. That is the trap the lease holdover
        // carve-out records.
        //
        // `closed_at`/`closed_by_user_id`/`closure_notes` are the acts' own and stay writable.
        // **`issued_at` and `issued_by_user_id` are NOT**: they are WHO authorised hazardous work
        // and WHEN, the most audit-sensitive pair on the row, and freezing them costs nothing
        // because `issue()` — their only writer — runs from `draft`, where this guard has already
        // returned. `reference` is frozen for the same reason: it is not fillable, so no form can
        // reach it, but it is the number quoted at the gate and on the radio.
        //
        // `status` is handled separately below, because it must keep MOVING (that is the workflow)
        // while never moving BACKWARDS.
        //
        // `canEdit()` is deliberately NOT the lever. The acts live on the record page and gate on
        // `canIssue()`, so refusing the page would strand *close* and *cancel* for exactly the
        // permits that need them — the (role, state) reachability trap `RowActionPolicy` records.
        // The FORM is disabled instead (`WorkPermitForm`), which is the UI truth beside this gate.
        static::updating(function (self $permit) {
            if ($permit->getOriginal('status') === self::STATUS_DRAFT) {
                // …except that a permit may not be sent BACK to draft. Nothing in the panel offers
                // it, but this guard has to hold for an import or an API write the way the window
                // guard above it does — and the route out is the whole freeze: closed → draft →
                // rewrite everything → issue again, a second authorisation on one reference with
                // the previous closure still on the row.
                return;
            }

            if ($permit->isDirty('status') && $permit->status === self::STATUS_DRAFT) {
                throw new DomainException(__('admin.refusals.work_permit_issued_is_fixed'));
            }

            $substance = array_intersect(array_keys($permit->getDirty()), [
                'type', 'vendor_id', 'contractor_name', 'contractor_phone', 'facility_work_order_id',
                'unit_id', 'area_id', 'location', 'description', 'conditions', 'valid_from', 'valid_to',
                'asset_id', 'issued_at', 'issued_by_user_id', 'reference',
            ]);

            if ($substance !== []) {
                throw new DomainException(__('admin.refusals.work_permit_issued_is_fixed'));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'work_permit');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FacilityWorkOrder::class, 'facility_work_order_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * Is work authorised RIGHT NOW under this permit?
     *
     * Issued, inside its window, and not yet closed. This is the question a security guard asks at
     * the door, and the only one that should ever be answered by a badge on a screen.
     */
    public function isLive(?CarbonImmutable $at = null): bool
    {
        $at = $at ?? CarbonImmutable::now();

        return $this->status === self::STATUS_ISSUED
            && $this->valid_from !== null && $this->valid_to !== null
            && $at->gte(CarbonImmutable::parse($this->valid_from))
            && $at->lte(CarbonImmutable::parse($this->valid_to));
    }

    /**
     * Issued, its window has passed, and nobody closed it — the safety finding.
     *
     * Not "expired": expiry is neutral, this is an omission. Somebody authorised hazardous work and
     * no one recorded that it stopped and the area was checked.
     */
    public function hasLapsed(?CarbonImmutable $at = null): bool
    {
        $at = $at ?? CarbonImmutable::now();

        return $this->status === self::STATUS_ISSUED
            && $this->valid_to !== null
            && $at->gt(CarbonImmutable::parse($this->valid_to));
    }

    /** The query twin of {@see hasLapsed} — shared by the scan, the dashboard card and the filter. */
    public function scopeOverdueClosure(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        return $query
            ->where('status', self::STATUS_ISSUED)
            ->where('valid_to', '<', ($at ?? CarbonImmutable::now())->toDateTimeString());
    }

    /** The query twin of {@see isLive}. */
    public function scopeLive(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        $now = ($at ?? CarbonImmutable::now())->toDateTimeString();

        return $query
            ->where('status', self::STATUS_ISSUED)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>=', $now);
    }

    /**
     * What an operator would type to find this permit.
     *
     * The reference is folded in the `created` hook rather than at `saving`, because
     * `AllocatesDocumentNumber` assigns it in `creating` — a saving-only fold would store a blob
     * with no permit number in it, which is the one thing anybody types.
     *
     * Only the row's OWN attributes: reaching through to `vendor->name` would strand every blob the
     * day a contractor is renamed. The vendor is reached through `vendor.search_text` instead.
     *
     * @return array<int, string|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->type,
            $this->contractor_name,
            $this->location,
            $this->description,
        ];
    }

    public function label(): string
    {
        return $this->reference.' · '.(__('admin.enums.work_permit_type')[$this->type] ?? $this->type);
    }

    private static function referencePrefix(): string
    {
        return DocumentNumbering::prefixFor('work_permit').'-'.now()->format('Y').'-';
    }

    private static function generateUniqueReference(): string
    {
        $prefix = static::referencePrefix();

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            // LENGTH first: a plain string sort puts `…-9999` above `…-10000`, so once a
            // series passes its zero-padding MAX returns the wrong row (EG-10).
            ->orderByRaw('LENGTH(reference) DESC, reference DESC')
            ->value('reference');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
