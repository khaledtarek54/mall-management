<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Services\SyncCamPoolFromLedgerService;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[DeletableWhenUnused(blockedBy: ['allocations'], instead: 'void the allocations first — they are what tenants were billed from')]
#[PropertyOwned]
class CamExpensePool extends Model
{
    use HasFactory, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    public const STATUSES = ['draft', 'reconciling', 'reconciled', 'closed'];

    /** The pool's totals are typed by a human — the legacy behaviour, and the default. */
    public const BASIS_STATED = 'stated';

    /** `total_actual_expense` is summed from posted GL lines on the pool's accounts (RC-01). */
    public const BASIS_LEDGER = 'ledger';

    /** `total_estimated_collected` is what tenants were actually invoiced in the year (RC-05). */
    public const BASIS_BILLED = 'billed';

    /**
     * The two bases an EXPENSE figure can come from, and the two an ESTIMATE can.
     *
     * Named as sets rather than left as three loose constants because `ValueSets` enforces the
     * columns and the forms offer them, and those two lists must be the same list. `billed` is an
     * estimate basis only — you cannot state an actual from what was billed, that is circular.
     *
     * @var list<string>
     */
    public const EXPENSE_BASES = [self::BASIS_STATED, self::BASIS_LEDGER];

    /** @var list<string> */
    public const ESTIMATE_BASES = [self::BASIS_STATED, self::BASIS_BILLED];

    /**
     * Invoice line types that ARE the monthly CAM estimate.
     *
     * `service_charge` is what Atriom bills monthly and reconciles annually; `cam_recovery` and
     * `cam_admin_fee` are the RESULT of a reconciliation and must never be counted as estimates
     * paid, or last year's true-up would inflate this year's estimate and the pool would chase its
     * own tail.
     */
    public const ESTIMATE_ITEM_TYPES = ['service_charge'];

    /** Shares divide by the summed area of the participating leases — legacy, and the default. */
    public const DENOMINATOR_OCCUPIED = 'occupied';

    /** Shares divide by the property's gross leasable area, so vacancy sits with the landlord. */
    public const DENOMINATOR_GLA = 'gla';

    /** Shares divide by a contractually pinned m² figure. */
    public const DENOMINATOR_FIXED = 'fixed';

    /** @var list<string> */
    public const DENOMINATOR_BASES = [self::DENOMINATOR_OCCUPIED, self::DENOMINATOR_GLA, self::DENOMINATOR_FIXED];

    /** The property's CAM pool — what every pool written before RC-02 is, and the default code. */
    public const CODE_CAM = 'cam';

    /** Every active lease on the property participates — legacy behaviour, and the default. */
    public const PARTICIPANTS_ALL = 'all';

    /**
     * Only leases whose units sit in one area participate (story RC-02).
     *
     * Yardi's own example is literally a zone: everyone shares CAM, but only the food court shares
     * grease-trap cleaning. Scoping to `units.area_id` rather than to a hand-kept lease list means
     * the participant set re-answers correctly when a lease moves units, instead of going stale in
     * a way nobody would notice until a tenant is billed for a pool they left.
     */
    public const PARTICIPANTS_AREA = 'area';

    protected $fillable = [
        'asset_id',
        'period_year',
        'pool_code',
        'name',
        'participant_scope',
        'participant_area_id',
        'total_actual_expense',
        'total_estimated_collected',
        'expense_basis',
        'estimate_basis',
        'estimate_charge_codes',
        'denominator_basis',
        'denominator_fixed_sqm',
        'denominator_used_sqm',
        'landlord_unrecovered_amount',
        'gross_up_pct',
        'variable_pct',
        'controllable_pct',
        'grossed_up_expense',
        'admin_fee_pct',
        'admin_fee_on_net',
        'recovery_vat_rate',
        'status',
        'notes',
        'reconciled_at',
        'reconciled_by_user_id',
    ];

    /**
     * The zone whose leases participate, when the pool is area-scoped (RC-02).
     *
     * @return BelongsTo<Area, $this>
     */
    public function participantArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'participant_area_id');
    }

    /** What to call this pool on a screen: the operator's name, else the code. */
    public function label(): string
    {
        return $this->name ?: strtoupper((string) $this->pool_code);
    }

    /**
     * DB defaults, mirrored on the model.
     *
     * A pool read straight after `create()` would otherwise report a null code — the column default
     * only lands in the database — and `label()` would render an empty badge on the screen that
     * just created it.
     */
    protected $attributes = [
        'pool_code' => self::CODE_CAM,
        'participant_scope' => self::PARTICIPANTS_ALL,
    ];

    protected $casts = [
        'expense_synced_at' => 'datetime',
        'denominator_fixed_sqm' => 'decimal:2',
        'denominator_used_sqm' => 'decimal:2',
        'landlord_unrecovered_amount' => 'decimal:2',
        'gross_up_pct' => 'decimal:2',
        'variable_pct' => 'decimal:2',
        'controllable_pct' => 'decimal:2',
        'grossed_up_expense' => 'decimal:2',
        'total_actual_expense' => 'decimal:2',
        'total_estimated_collected' => 'decimal:2',
        'admin_fee_pct' => 'decimal:4',
        'admin_fee_on_net' => 'boolean',
        'recovery_vat_rate' => 'decimal:2',
        'reconciled_at' => 'datetime',
        'estimate_charge_codes' => 'array',
    ];

    /**
     * Which billed charge codes ARE this pool's estimate.
     *
     * `ESTIMATE_ITEM_TYPES` is the FLOOR for an undeclared `cam` pool, not the policy — the same
     * shape as `Vat::EXEMPT_TYPES`. It exists so every row written before the column did keeps
     * reconciling exactly as it did, and for nothing else.
     *
     * **Any other pool must say what it bills.** A `tax` pool inheriting the global constant is the
     * bug this column closes: it subtracted the tenant's entire year of billed *service charge*
     * from its own allocation, reconciled to a large negative, and issued a credit note against
     * live AR. An empty return is therefore not "count nothing" — it is "not declared", and
     * `SyncCamPoolFromLedgerService` refuses the billed basis rather than guessing.
     *
     * @return array<int, string>
     */
    public function estimateChargeCodes(): array
    {
        $declared = $this->estimate_charge_codes;

        if (is_array($declared)) {
            $declared = array_values(array_filter($declared, fn ($code): bool => is_string($code) && $code !== ''));

            if ($declared !== []) {
                return $declared;
            }
        }

        return $this->pool_code === self::CODE_CAM ? self::ESTIMATE_ITEM_TYPES : [];
    }

    /**
     * The leases that belong to this pool — the ONE definition of participation.
     *
     * Named once because two callers need it and they must not drift: the reconciliation picks
     * allocation targets with it, and the billed-estimate query subtracts with it. They disagreed
     * until 2026-08-11 — the estimate query had no participant filter at all, so a food-court pool
     * subtracted the whole property's billing from the food court's allocation.
     *
     * Deliberately NOT filtered by lease status. Allocation targets must be live leases (the
     * reconciliation adds `active` itself), but a departed tenant WAS billed during the year being
     * reconciled and their estimate is part of what the pool collected. Filtering them out here
     * would understate collections and over-recover from whoever is left.
     *
     * Area scope reads the `lease_unit` PIVOT, never the denormalised master `unit_id` alone: a
     * multi-unit lease whose master sits outside the zone but whose annexe sits inside it still
     * participates.
     *
     * @return Builder<Lease>
     */
    public function participantLeaseQuery(): Builder
    {
        return Lease::query()
            ->whereHas('unit', fn ($q) => $q->where('asset_id', $this->asset_id))
            ->when(
                $this->participant_scope === self::PARTICIPANTS_AREA && $this->participant_area_id,
                fn ($q) => $q->whereHas('units', fn ($u) => $u->where('units.area_id', $this->participant_area_id)),
            );
    }

    protected static function booted(): void
    {
        // Freeze the recovery basis once billing has started. If the actual-expense
        // or estimated-collected figure changes after ANY allocation is billed,
        // generateAllocations recomputes only the still-pending allocations against
        // the new figure while billed ones keep the old amount — silently over- or
        // under-recovering the pool. Correcting a reconciled figure requires voiding
        // the billed allocations first (docs/modules/08-cam.md §8).
        static::updating(function (CamExpensePool $pool) {
            // admin_fee_pct/admin_fee_on_net are recovery-basis inputs too: changing the fee rate
            // after some allocations are billed would leave billed rows on the old rate while
            // re-generated pending rows recompute on the new one → different fees for equal shares
            // in one pool. Freeze them alongside the expense/estimate basis.
            $basisChanged = $pool->isDirty('total_actual_expense')
                || $pool->isDirty('total_estimated_collected')
                || $pool->isDirty('admin_fee_pct')
                || $pool->isDirty('admin_fee_on_net')
                || $pool->isDirty('recovery_vat_rate'); // changing the recovery VAT after billing would leave billed rows on the old rate

            if ($basisChanged && $pool->allocations()->where('status', '!=', 'pending')->exists()) {
                throw new \DomainException(__('admin.refusals.cam_basis_locked_after_billing'));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'cam_pool');
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The GL accounts whose posted expense makes up this pool (RC-01).
     *
     * Empty on every pool created before this existed, which is exactly why `expense_basis` defaults
     * to `stated`: a pool with no accounts and a ledger basis would recover nothing.
     *
     * @return BelongsToMany<LedgerAccount, $this>
     */
    public function ledgerAccounts(): BelongsToMany
    {
        return $this->belongsToMany(LedgerAccount::class, 'cam_pool_accounts')
            ->withPivot('cost_nature', 'is_controllable')
            ->withTimestamps();
    }

    /**
     * The expense the shares are actually apportioned over (story RC-04).
     *
     * Without gross-up this is simply `total_actual_expense`, which is every pool that exists
     * today. With it, the VARIABLE portion is scaled to the occupancy assumption first:
     *
     *     basis = fixed + variable × (assumption ÷ actual occupancy)
     *
     * so a tenant pays what they would pay in a full centre rather than a discount funded by their
     * neighbours' vacancy. Fixed costs are never grossed — a security contract costs the same empty
     * or full, and the vacancy share of it is genuinely the landlord's.
     *
     * **Refused on an `occupied` denominator.** There the shares already sum to 100%, so grossing
     * up would bill tenants MORE than the landlord spent. Returning the ungrossed total is the
     * honest answer rather than quietly over-recovering; the form hides the field on that basis and
     * the statement never claims a gross-up that did not happen.
     *
     * Never scales DOWN: an occupancy already above the assumption means the mall is fuller than
     * the clause contemplated, and the tenants' own shares already reflect that.
     */
    public function apportionmentBasis(float $actualOccupancyPct): float
    {
        $total = (float) $this->total_actual_expense;
        $assumption = $this->gross_up_pct !== null ? (float) $this->gross_up_pct : 0.0;

        if ($assumption <= 0
            || $this->denominator_basis === self::DENOMINATOR_OCCUPIED
            || $actualOccupancyPct <= 0
            || $actualOccupancyPct >= $assumption) {
            return round($total, 2);
        }

        $variableShare = $this->variableShare();

        if ($variableShare <= 0) {
            return round($total, 2);
        }

        $variable = $total * $variableShare;
        $fixed = $total - $variable;

        return round($fixed + $variable * ($assumption / $actualOccupancyPct), 2);
    }

    /**
     * How much of the pool is variable, as a fraction.
     *
     * Derived from the attached accounts when the expense came from the ledger — the accounts know
     * their own nature — and read from `variable_pct` when the total was typed. A pool that says
     * nothing is 0% variable, so gross-up does nothing rather than something arbitrary.
     */
    public function variableShare(): float
    {
        if ($this->expense_basis === self::BASIS_LEDGER && $this->ledgerAccounts->isNotEmpty()) {
            return app(SyncCamPoolFromLedgerService::class)->variableShareFromLedger($this);
        }

        return $this->variable_pct !== null ? (float) $this->variable_pct / 100 : 0.0;
    }

    /**
     * How much of the pool is CONTROLLABLE, as a fraction (story RC-07).
     *
     * A different axis from {@see variableShare()}: a security contract is fixed AND controllable,
     * utilities are variable and NOT controllable, insurance is fixed and not controllable.
     * Conflating the two would cap the wrong half of the pool.
     *
     * Defaults to 1.0 — everything controllable — which is what makes a controllable-scoped cap on
     * an unclassified pool behave exactly like the unscoped cap it replaces.
     */
    public function controllableShare(): float
    {
        if ($this->expense_basis === self::BASIS_LEDGER && $this->ledgerAccounts->isNotEmpty()) {
            return app(SyncCamPoolFromLedgerService::class)->controllableShareFromLedger($this);
        }

        return $this->controllable_pct !== null ? (float) $this->controllable_pct / 100 : 1.0;
    }

    /** Is either total derived rather than typed? */
    public function isDerived(): bool
    {
        return $this->expense_basis === self::BASIS_LEDGER
            || $this->estimate_basis === self::BASIS_BILLED;
    }

    /** @return HasMany<CamAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(CamAllocation::class);
    }

    public function reconciledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function variance(): float
    {
        return (float) $this->total_actual_expense - (float) $this->total_estimated_collected;
    }

    /**
     * WHAT THIS RECONCILIATION COST THE LANDLORD, SPLIT BY CAUSE.
     *
     * Yardi's recovery worksheet always shows the landlord's side of a pool, and splits it, because
     * the two halves are different decisions with different levers:
     *
     *  - **vacancy** — cost allocated to nobody, which follows from `denominator_basis`. On a `gla`
     *    denominator the landlord elected to carry the empty space; on `occupied` the sitting
     *    tenants do and this is zero. Stored as `landlord_unrecovered`.
     *  - **caps** — cost allocated to a tenant and then refused by their cap clause. Summed from
     *    the allocations, because the cap is a per-lease term and the pool has no column for it.
     *
     * Atriom reported only the first, and only to services — `landlord_unrecovered` appears on no
     * screen at all. So a pool where a cap absorbed 33,138.60 answered "unrecovered: 0.00", which
     * is true of the column and false of the question anyone asks it (2026-08-31).
     *
     * NOT folded into one figure: conflating them hides which lever moves the number. Changing the
     * denominator is a pool decision; a cap is a lease term renegotiated at renewal.
     *
     * @return array{vacancy: float, caps: float, total: float}
     */
    public function landlordShare(): array
    {
        // `landlord_unrecovered_AMOUNT` — the full column name. Reading `landlord_unrecovered`
        // returns an undefined attribute, i.e. null, i.e. a silent 0.00 for the vacancy half, which
        // is exactly the wrong answer this method exists to stop reporting. Caught by its own test.
        //
        // FLOORED AT ZERO. The column is `actual − Σ allocated`, so the per-tenant rounding leaves a
        // residue of a piastre or two either way; a real pool rendered "EGP −0.02 on vacant space",
        // which reads as a fault rather than as nothing. A negative residue means the allocations
        // slightly over-cover the pool — the landlord bears nothing from vacancy — and a genuine
        // over-allocation is a different problem, which `billing:reconcile`'s tie-out already owns.
        $vacancy = round(max(0.0, (float) $this->landlord_unrecovered_amount), 2);
        $caps = round((float) $this->allocations()->sum('cap_absorbed_amount'), 2);

        return ['vacancy' => $vacancy, 'caps' => $caps, 'total' => round($vacancy + $caps, 2)];
    }

    /**
     * Are this pool's headline totals DERIVED but never sourced?
     *
     * `expense_basis = ledger` and `estimate_basis = billed` both mean "this column is computed from
     * documents, not typed" — and the computation only happens when someone runs Sync from ledger.
     * Until then the column holds whatever it was created with, and `variance()` subtracts it anyway.
     *
     * Found the hard way (2026-08-17): a pool on `estimate_basis = billed` sat at
     * `total_estimated_collected = 0` while its three tenants had been invoiced 346,000 of service
     * charge, so the list reported a variance of 500,000 against a true 154,000. Nothing on any
     * screen said the figure had never been sourced — `expense_synced_at` was null and appeared on
     * neither the list nor the form. The ALLOCATIONS were right the whole time, because they derive
     * each lease's estimate from its own invoices; only the header lied, which is the harder kind of
     * wrong to notice.
     *
     * Limit, stated rather than implied: this answers "never sourced", not "sourced and since gone
     * stale". A pool synced in January and reconciled in December reads as fine here. Detecting that
     * means comparing against the newest contributing document per row, which is a query per row on
     * a list — worth doing when someone is actually bitten by it.
     */
    public function needsSourcing(): bool
    {
        // `isDerived()` is the one definition of "these columns are computed" — the Sync action's
        // own visibility reads it, so warning about a pool the action would not even offer is not a
        // state that can exist.
        return $this->isDerived() && $this->expense_synced_at === null;
    }

    public function isReconciled(): bool
    {
        return in_array($this->status, ['reconciled', 'closed']);
    }
}
