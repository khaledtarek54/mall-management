<?php

namespace App\Models;

use App\Contracts\BillableAgreement;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DeletionAllowed(reason: 'operational: voided through the pool, not removed')]
#[PropertyOwned(via: 'pool')]
class CamAllocation extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['pending', 'billed', 'disputed', 'closed'];

    protected $fillable = [
        'cam_expense_pool_id',
        'lease_id',
        'unit_ownership_id',
        'pro_rata_share_pct',
        'allocated_amount',
        'excluded_amount',
        'estimated_paid',
        'true_up_amount',
        'admin_fee_amount',
        'admin_fee_vat_amount',
        'cap_amount',
        'capped_cost_amount',
        'cap_absorbed_amount',
        'cap_headroom_used',
        'cap_headroom_banked',
        'proposed_monthly_estimate',
        'estimate_applied_at',
        'exclusions',
        'status',
        'billed_charge_id',
        'billed_credit_note_id',
        'billed_admin_fee_charge_id',
    ];

    protected $casts = [
        'cap_headroom_used' => 'decimal:2',
        'cap_headroom_banked' => 'decimal:2',
        'proposed_monthly_estimate' => 'decimal:2',
        'estimate_applied_at' => 'datetime',
        'pro_rata_share_pct' => 'decimal:4',
        'allocated_amount' => 'decimal:2',
        'excluded_amount' => 'decimal:2',
        'estimated_paid' => 'decimal:2',
        'true_up_amount' => 'decimal:2',
        'admin_fee_amount' => 'decimal:2',
        'admin_fee_vat_amount' => 'decimal:2',
        'cap_amount' => 'decimal:2',
        'capped_cost_amount' => 'decimal:2',
        'cap_absorbed_amount' => 'decimal:2',
        'exclusions' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(fn (self $a) => $a->assertBelongsToExactlyOneAgreement());
    }

    /** @return BelongsTo<CamExpensePool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(CamExpensePool::class, 'cam_expense_pool_id');
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * The unit ownership this share belongs to — null for a lease allocation.
     *
     * @return BelongsTo<UnitOwnership, $this>
     */
    public function unitOwnership(): BelongsTo
    {
        return $this->belongsTo(UnitOwnership::class);
    }

    /**
     * The shares belonging to one tenant — through EITHER agreement.
     *
     * **The OR branch is the whole predicate, not a refinement.** An allocation belongs to a lease
     * *or* to a unit ownership, because a unit owner is a CAM participant in his own right; scoping
     * through `lease` alone returns NOTHING for him, which is exactly how an owner came to be
     * billed a true-up whose basis he could not see. The portal's resource states this in its own
     * `getEloquentQuery()`, and the mobile API needed the same answer — so it is defined ONCE here
     * rather than copied into a third and fourth place.
     *
     * The two clauses are GROUPED inside one closure deliberately. `AND` binds tighter than `OR`,
     * so written flat alongside any other constraint the ownership branch escapes the tenant scope
     * entirely — the same trap `Tenant::creditBalance()` and `CamExpensePool`'s participant query
     * each carry a note about.
     *
     * @param  Builder<CamAllocation>  $query
     */
    public function scopeOwnedBy(Builder $query, Tenant|int $tenant): void
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        $query->where(fn (Builder $q) => $q
            ->whereHas('lease', fn (Builder $l) => $l->where('tenant_id', $tenantId))
            ->orWhereHas('unitOwnership', fn (Builder $o) => $o->where('tenant_id', $tenantId)));
    }

    /**
     * WHO this share belongs to, without the caller asking which kind.
     *
     * Both implement `BillableAgreement`, so the true-up can be billed to either through the same
     * seam. Callers that need lease LAW — a ceiling, a stated share, banked headroom — must still
     * ask for `lease` explicitly and handle its absence: a sale carries no CAM clause, which is the
     * whole reason an ownership takes the plain pro-rata path.
     */
    public function agreement(): ?BillableAgreement
    {
        return $this->lease ?? $this->unitOwnership;
    }

    /**
     * WHO this share is billed to — a lease's tenant, or the OWNER of a sold unit.
     *
     * **`UnitOwnership`'s relation is `owner`, not `tenant`**, named for what the operator calls
     * them over the same `tenants` table. Reading `->tenant` on it returns NULL rather than
     * throwing — an undefined relation is just a missing attribute — so every reader written as
     * `lease?->tenant ?? unitOwnership?->tenant` LOOKS like it handles both agreements and answers
     * null for every owner. Measured 2026-09-05 on `mall_management_qa`, `unit_ownerships#1`:
     * `owner->name` = "Ashraf El-Gindy", `->tenant` = NULL, `method_exists($o, 'tenant')` = false.
     *
     * Three readers had that exact shape, and the worst is the document the owner audits:
     * `CamStatementPdfService::document()` carries a comment saying it was written to stop an
     * ownership statement rendering "a blank party" and then reads the relation that does not
     * exist — so that fix was inert from the day it shipped, and the party block on a unit owner's
     * own service-charge statement has been empty ever since. The other two are the `->recipient()`
     * closures on the two statement download buttons, where a null party costs the document its
     * LANGUAGE: `DocumentLocale::resolve()` reads the recipient's stored `locale`, so an owner who
     * reads Arabic was sent whatever the operator's panel happened to be in.
     *
     * Answered once, here, so a fourth reader cannot repeat it. Nullable because both parents
     * soft-delete.
     */
    public function counterparty(): ?Tenant
    {
        return $this->lease?->tenant ?? $this->unitOwnership?->owner;
    }

    /**
     * The unit this share is against, whichever agreement holds it.
     *
     * The twin of {@see Invoice::unitCode()}, for the same reason: an allocation belongs to a
     * lease OR an ownership — `assertBelongsToExactlyOneAgreement()` enforces exactly one — and
     * each of them holds the unit differently.
     *
     * Null stays possible and stays correct: a multi-unit lease has no single unit.
     */
    public function unitCode(): ?string
    {
        return $this->lease?->unit?->code ?? $this->unitOwnership?->unit?->code;
    }

    /**
     * {@see unitCode()} under an ATTRIBUTE name — a second road to the rule, never a second answer.
     *
     * It exists because a `TextEntry` and a `TextColumn` name a STATE PATH, and a path cannot
     * branch on which agreement holds the row. The portal's View page named `lease.unit.code` and
     * so rendered an empty cell for every unit owner — on the one screen he opens to read the
     * basis of a true-up he did not expect, four lines below a `getEloquentQuery()` whose own
     * comment says his allocation has no lease. `Invoice` grew the identical accessor for the
     * identical five readers.
     *
     * **NOT in `$appends`, deliberately.** `docs/api/openapi.json` is generated from `toArray()`,
     * so appending it would rewrite the generated mobile contract as a side effect of a panel fix.
     */
    public function getUnitCodeAttribute(): ?string
    {
        return $this->unitCode();
    }

    /**
     * The FK that ties a document raised for this allocation back to its agreement.
     *
     * Used for the anchor `Charge`, and to find the agreement's open invoices when an over-recovery
     * is credited. Returned as an array so a caller never has to branch on which kind it is.
     *
     * @return array<string, int>
     */
    public function chargeLink(): array
    {
        return $this->lease_id !== null
            ? ['lease_id' => $this->lease_id]
            : ['unit_ownership_id' => $this->unit_ownership_id];
    }

    /**
     * Exactly one agreement — a lease OR an ownership, never both, never neither.
     *
     * On the model rather than as a CHECK constraint: SQLite drops CHECKs on any later `->change()`
     * to the table, and this table has just had one. "Neither" is the silent case — an allocation
     * attached to nothing still counts toward the pool's tie-out while belonging to no party, so
     * the pool would reconcile perfectly and bill nobody.
     *
     * @throws \DomainException
     */
    public function assertBelongsToExactlyOneAgreement(): void
    {
        if (($this->lease_id !== null) === ($this->unit_ownership_id !== null)) {
            throw new \DomainException(__('admin.errors.cam_allocation_needs_one_agreement'));
        }
    }

    public function billedCharge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'billed_charge_id');
    }

    /** Set instead of billedCharge when the true-up is a credit (negative). */
    public function billedCreditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'billed_credit_note_id');
    }

    public function isBilled(): bool
    {
        return $this->status === 'billed';
    }

    /**
     * The rate at which this pool recovers VAT on its true-up.
     *
     * Null pool (both parents soft-delete) and a null column both mean 0, which is verbatim what
     * the two billing methods did with their `?? 0`.
     */
    public function recoveryVatRate(): float
    {
        return (float) ($this->pool?->recovery_vat_rate ?? 0);
    }

    /**
     * The VAT a true-up of `$net` carries — ON TOP of the net, never inside it.
     *
     * ONE definition, because there were four readers and one of them had no answer at all. The
     * recovery invoice (`CamReconciliationService::billChargeImmediately()`), the credit note
     * (`billCredit()`) and the operator's Breakdown modal (`explainAllocation()`) each computed it;
     * the TENANT'S OWN statement (`CamStatementPdfService::facts()`) did not, so "Total now due"
     * omitted the tax the invoice beside it charges. `cam_expense_pools.recovery_vat_rate` ships
     * `default(14.00)`, so that was every pool on every install.
     *
     * Rounded to 2dp here, exactly as both documents round it, so no reader can disagree with them
     * by a rounding step — the modal's credit branch used `net × (1 + rate/100)` and did.
     */
    public function recoveryVatOn(float $net): float
    {
        return round($net * $this->recoveryVatRate() / 100, 2);
    }

    public function isOverPaid(): bool
    {
        return (float) $this->true_up_amount < 0;
    }
}
