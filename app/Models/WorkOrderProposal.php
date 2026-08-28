<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * عرض سعر — a contractor's quote for work that will exceed the job's not-to-exceed amount.
 *
 * **The before-the-money control** (ServiceChannel §3). Without it the operator sees the number when
 * the invoice arrives, which is not a negotiation — scenario S4's leak that became EGP 46,000.
 *
 * ## It is the ESTIMATE, in the cost object's own vocabulary
 *
 * The three buckets are `est_labour_cost` / `est_material_cost` / `est_service_cost`, and approving
 * a proposal writes them onto the job. That makes step 2's planned-vs-actual variance mean *"did the
 * contractor deliver what they quoted?"* — the question the loop exists to answer — instead of two
 * unrelated sets of numbers about the same work.
 *
 * ## Who sent it — two columns, two questions
 *
 * `submitted_by_user_id` is an OPERATOR: our staff member keyed this on the contractor's behalf,
 * exactly as a vendor bill is. `submitted_by_vendor_contact_id` is the CONTRACTOR: they sent it
 * themselves from `/vendor` (step 5 of the portal design, built 2026-08-28). Exactly one is set;
 * both null is a legacy row from before either was recorded.
 *
 * Two nullable columns rather than a morph, because they answer different questions rather than
 * offering two types for one — and the difference is the point: a transcribed quote carries whatever
 * the phone call carried, and an operator reading it should be able to tell.
 */
#[DeletionAllowed(reason: 'a quote keyed against the wrong job is ordinary cleanup — nothing posts, and the work order simply re-reads its estimate. A DECIDED proposal is refused by the service instead, because that one is a record of an answer somebody gave')]
#[PropertyOwned(via: 'workOrder')]
class WorkOrderProposal extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /** Waiting for the operator's answer. */
    public const STATUS_SUBMITTED = 'submitted';

    /** Approved — the NTE is raised to it and the job's estimate is set from it. */
    public const STATUS_APPROVED = 'approved';

    /** Refused. The work does not proceed at this price. */
    public const STATUS_REJECTED = 'rejected';

    /** Taken back by the contractor before an answer. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN];

    /** An answer has been given; the record is now history rather than a pending question. */
    public const DECIDED = [self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $fillable = [
        'facility_work_order_id', 'vendor_id', 'status', 'is_supplementary',
        'labour_amount', 'material_amount', 'service_amount', 'total_amount',
        'scope', 'decision_reason', 'submitted_by_user_id', 'submitted_by_vendor_contact_id', 'submitted_at',
        'decided_by_user_id', 'decided_at',
    ];

    protected $casts = [
        'is_supplementary' => 'boolean',
        'labour_amount' => 'decimal:2',
        'material_amount' => 'decimal:2',
        'service_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_SUBMITTED];

    protected static function booted(): void
    {
        // The total is DERIVED from its parts, never typed. A quote whose total disagrees with its
        // own breakdown is the argument nobody wants to have when the invoice arrives.
        static::saving(function (self $proposal) {
            $proposal->total_amount = round(
                (float) $proposal->labour_amount
                + (float) $proposal->material_amount
                + (float) $proposal->service_amount,
                2,
            );
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'work_order_proposal');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FacilityWorkOrder::class, 'facility_work_order_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** The contractor's own person, when they sent the quote themselves rather than phoning it in. */
    public function submittedByContact(): BelongsTo
    {
        return $this->belongsTo(VendorContact::class, 'submitted_by_vendor_contact_id');
    }

    /** True when this quote arrived from the contractor rather than being transcribed by staff. */
    public function wasSelfSubmitted(): bool
    {
        return $this->submitted_by_vendor_contact_id !== null;
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isDecided(): bool
    {
        return in_array($this->status, self::DECIDED, true);
    }

    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }
}
