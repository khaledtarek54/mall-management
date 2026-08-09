<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One band of a percentage-rent breakpoint ladder. See the migration for why a ladder exists.
 */
class LeasePercentageRentTier extends Model
{
    use HasFactory;

    protected $fillable = ['lease_id', 'from_amount', 'to_amount', 'rate'];

    protected $casts = [
        'from_amount' => 'decimal:2',
        'to_amount' => 'decimal:2',
        'rate' => 'decimal:2',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * Percentage rent on `$sales` across a ladder — **the one place the band arithmetic lives.**
     *
     * Each band charges only the sales that fall WITHIN it. A tenant at 1,000,000 against
     * 0–500K@0% · 500K–900K@5% · 900K+@6% owes 400,000 × 5% + 100,000 × 6% = 26,000 — not
     * 1,000,000 × 6%. Charging the top rate on the whole figure is the classic way to overcharge
     * every large tenant, so this is deliberately not inlined anywhere.
     *
     * @param  Collection<int, self>|iterable<self>  $tiers
     */
    public static function overageFor(iterable $tiers, float $sales): float
    {
        $total = 0.0;

        foreach ($tiers as $tier) {
            $from = (float) $tier->from_amount;

            if ($sales <= $from) {
                continue;
            }

            $ceiling = $tier->to_amount !== null ? (float) $tier->to_amount : $sales;
            $within = min($sales, $ceiling) - $from;

            if ($within > 0) {
                $total += $within * ((float) $tier->rate / 100.0);
            }
        }

        return round($total, 2);
    }

    /**
     * Ordered bands for a lease. Sorted by floor because the ladder's meaning is positional and a
     * mis-ordered set would charge the wrong rate on the wrong slice.
     *
     * @return Collection<int, self>
     */
    public static function ladderFor(Lease $lease): Collection
    {
        return $lease->relationLoaded('percentageRentTiers')
            ? $lease->percentageRentTiers->sortBy('from_amount')->values()
            : static::query()->where('lease_id', $lease->id)->orderBy('from_amount')->get();
    }
}
