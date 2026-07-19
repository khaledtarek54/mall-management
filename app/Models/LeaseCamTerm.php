<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
class LeaseCamTerm extends Model
{
    use HasFactory;

    public const CAP_TYPES = ['absolute', 'yoy', 'both'];

    protected $fillable = [
        'lease_id',
        'effective_year',
        'cap_type',
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
        'compounding' => 'boolean',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * The cost-share ceiling this term imposes for a given reconciliation year, or null if it
     * resolves to no usable ceiling (missing inputs). When cap_type = 'both', the tighter of the
     * absolute and YoY ceilings wins (min) — the tenant gets the more protective cap.
     */
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
