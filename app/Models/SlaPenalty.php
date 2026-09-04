<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Support\ActivityLogging;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateNotOperatorTyped;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An SLA penalty against an external maintenance company (FR-CM-08).
 *
 * One row per breaching work order (a DB unique). While the job is open the hourly scan
 * **re-computes this same row** rather than inserting another — which is what makes an
 * accruing (per-day) penalty safe without abusing `sla_breach_notified_at`, a once-per-record
 * alert stamp that could never serve as an accrual key. When the job reaches a terminal
 * state the amount is frozen (`final`).
 *
 * The terms are copied onto the row as applied. Re-deriving them from the contract at read
 * time would silently restate history the moment someone renegotiates the rate.
 */
#[NeverDeletable(correction: 'waive or release the penalty — it feeds the vendor bill')]
// asset_id copied from the breaching work order
#[PropertyOwned]
#[PostingDateNotOperatorTyped(reason: 'applied_at is stamped now() at the moment the penalty is applied — a penalty cannot be applied into the past.')]
class SlaPenalty extends Model
{
    use HasFactory, LogsActivity, RefusesDeletionOfCommittedRecords;

    /**
     * FR-CM-08 never says on what basis a penalty is computed, and the three readings behave
     * differently enough that guessing one would be a rewrite if wrong. So the basis is
     * configuration on the vendor's contract, not a decision baked into the code.
     */
    public const BASIS_FLAT = 'flat';

    public const BASIS_PER_DAY = 'per_day';

    public const BASIS_PERCENT_OF_VALUE = 'percent_of_value';

    public const BASES = [self::BASIS_FLAT, self::BASIS_PER_DAY, self::BASIS_PERCENT_OF_VALUE];

    public const STATUS_PENDING = 'pending';

    public const STATUS_FINAL = 'final';

    /** Charged to a vendor bill — distinct from `final` (assessed and owed, not yet deducted). */
    public const STATUS_APPLIED = 'applied';

    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'facility_work_order_id',
        'asset_id',
        'vendor_id',
        'vendor_contract_id',
        'vendor_bill_id',
        'basis',
        'rate',
        'hours_over_sla',
        'amount',
        'currency',
        'status',
        'finalised_at',
        'applied_at',
        'waived_at',
        'waived_by_user_id',
        'waive_reason',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'hours_over_sla' => 'integer',
        'finalised_at' => 'datetime',
        'applied_at' => 'datetime',
        'waived_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'currency' => 'EGP',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'sla_penalty');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FacilityWorkOrder::class, 'facility_work_order_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class, 'vendor_contract_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }

    /**
     * The bill this penalty is charged to, read under a ROW LOCK — the locking twin of {@see bill()}.
     *
     * `VendorBill::recompute()` re-derives `penalty_applied_amount`, `balance` and `status` from
     * EVERY applied penalty on the bill, so RELEASING one is a whole-bill write and has to serialise
     * on the bill row. `ApplySlaPenaltyService::toBill()` and `VoidVendorBillPaymentService` both do
     * that; the two paths that release — `ApplySlaPenaltyService::detach()` and
     * `AssessSlaPenaltyService::waive()`, in two different services — did not. They locked the
     * penalty and reached the bill through the plain relation, so a release racing an apply on the
     * same bill recomputed from a snapshot fixed before the apply committed and wrote a balance with
     * the OTHER penalty's deduction erased. The payable is then overstated by that penalty, and that
     * is money leaving on the next payment run.
     *
     * A LOCKING read, not a plain one behind a lock: under MySQL REPEATABLE READ a transaction's
     * consistent-read snapshot is fixed at its first PLAIN read, so `$this->bill` here would open the
     * read view BEFORE the wait and every figure `recompute()` then sums would be answered from
     * before it. That premise is measured and recorded in `ConcurrencyPolicy::AUTHORITATIVE_GUARDS`
     * (F-09, two processes on two connections); what was checked here is only the SQL shape — the
     * plain relation compiles to `select * from vendor_bills where id = ?` with no lock clause.
     *
     * **Called AFTER the penalty's own lock, never before.** `toBill()` takes penalty then bill, and
     * taking them the other way round here would deadlock against it.
     *
     * ONE method rather than a `lockForUpdate()` written into each service: two copies of one rule is
     * how the second one comes to be forgotten, which is precisely what this was.
     *
     * Null when the penalty is charged to nothing, which is the ordinary state of a `final` one.
     */
    public function billForUpdate(): ?VendorBill
    {
        if ($this->vendor_bill_id === null) {
            return null;
        }

        return VendorBill::query()->whereKey($this->vendor_bill_id)->lockForUpdate()->first();
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_user_id');
    }

    /** Still accruing — the job is open and the amount can still move. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isWaived(): bool
    {
        return $this->status === self::STATUS_WAIVED;
    }

    /** Chargeable to the vendor: assessed, frozen, not waived, not yet charged. */
    public function isChargeable(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    public function scopeChargeable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FINAL);
    }
}
