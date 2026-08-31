<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\Translate;
use DomainException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Effective-dated CAM cap terms for one lease — the ceiling a tenant's CAM cost share may not
 * exceed in a reconciliation year. Consumed by CamReconciliationService::generateAllocations,
 * which caps ONLY the true-up + admin-fee base; the raw allocated_amount stays uncapped so the
 * pool's cost partition still ties out. See docs/modules/08-cam.md §3.
 *
 * HARD-delete (no SoftDeletes): a cap term is forward-looking configuration, not a financial
 * record — historical allocations already froze their applied cap_amount, so removing a term
 * can't corrupt the past. Hard-delete keeps the unique(lease_id, effective_year) slot re-usable
 * (a soft-deleted row would collide with the index and block ever re-adding that year's cap).
 */
#[DeletionAllowed(reason: 'parent-managed: effective-dated terms on a lease')]
#[PropertyOwned(via: 'lease.unit')]
class LeaseCamTerm extends Model
{
    use HasFactory;

    public const CAP_TYPES = ['absolute', 'yoy', 'both'];

    protected $fillable = [
        'lease_id',
        'effective_year',
        'cap_type',
        'cap_scope',
        'cap_carry_forward',
        'stated_share_pct',
        'cap_absolute_amount',
        'base_year',
        'base_year_amount',
        'yoy_pct',
        'compounding',
        'notes',
    ];

    protected $casts = [
        'effective_year' => 'integer',
        'cap_absolute_amount' => 'decimal:2',
        'base_year' => 'integer',
        'base_year_amount' => 'decimal:2',
        'yoy_pct' => 'decimal:4',
        'stated_share_pct' => 'decimal:4',
        'compounding' => 'boolean',
        'cap_carry_forward' => 'boolean',
    ];

    /** The cap bites on the tenant's WHOLE share — the legacy scope, and the default. */
    public const SCOPE_TOTAL = 'total';

    /**
     * The cap bites only on CONTROLLABLE costs (story RC-07).
     *
     * Most cap clauses carve out rates, insurance and utilities, because a landlord cannot be asked
     * to absorb a government levy it does not set. Capping everything is more protective than the
     * contract, and the landlord was absorbing money it was entitled to recover.
     */
    public const SCOPE_CONTROLLABLE = 'controllable';

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * The cost-share ceiling this term imposes for a given reconciliation year, or null if it
     * resolves to no usable ceiling (missing inputs). When cap_type = 'both', the tighter of the
     * absolute and YoY ceilings wins (min) — the tenant gets the more protective cap.
     */
    /**
     * WHICH COLUMNS EACH CAP TYPE NEEDS TO RESOLVE TO A NUMBER.
     *
     * Mirrors the form's own `required()` rules, and is enforced on the MODEL because the form is
     * not the only writer. A `yoy` term with no `base_year_amount` SAVES, renders on the lease's
     * CAM tab like any other cap, and `resolveCeiling()` returns null — so the operator believes
     * the tenant is capped and the reconciliation bills them in full. Measured on the demo books
     * (2026-08-31): one of the two seeded cap terms was exactly that.
     *
     * The same reasoning as `TaxCode` refusing to activate a taxable code with no rate: an
     * incomplete term must be impossible, not inert. A cap that silently does nothing is worse
     * than no cap, because the second is visible.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_BY_TYPE = [
        'absolute' => ['cap_absolute_amount'],
        'yoy' => ['base_year', 'base_year_amount', 'yoy_pct'],
        'both' => ['cap_absolute_amount', 'base_year', 'base_year_amount', 'yoy_pct'],
    ];

    protected static function booted(): void
    {
        static::saving(function (self $term): void {
            $missing = array_values(array_filter(
                self::REQUIRED_BY_TYPE[$term->cap_type] ?? [],
                fn (string $column): bool => $term->{$column} === null || $term->{$column} === '',
            ));

            if ($missing !== []) {
                throw new DomainException(__('admin.refusals.cam_cap_term_incomplete', [
                    'type' => Translate::orHumanized("admin.enums.cam_cap_type.{$term->cap_type}", $term->cap_type),
                    'fields' => collect($missing)
                        ->map(fn (string $c): string => Translate::orHumanized('admin.fields.cam_'.str_replace('cap_', '', $c), $c))
                        ->join('، ', ' — '),
                ]));
            }
        });
    }

    public function resolveCeiling(int $reconciledYear): ?float
    {
        $ceilings = [];

        if (in_array($this->cap_type, ['absolute', 'both'], true) && $this->cap_absolute_amount !== null) {
            $ceilings[] = (float) $this->cap_absolute_amount;
        }

        if (in_array($this->cap_type, ['yoy', 'both'], true)
            && $this->base_year !== null && $this->base_year_amount !== null && $this->yoy_pct !== null) {
            $years = max(0, $reconciledYear - (int) $this->base_year);
            $pct = (float) $this->yoy_pct;
            $base = (float) $this->base_year_amount;
            $ceilings[] = $this->compounding
                ? round($base * (1 + $pct) ** $years, 2)      // compound growth
                : round($base * (1 + $pct * $years), 2);       // simple growth
        }

        return $ceilings === [] ? null : min($ceilings);
    }
}
