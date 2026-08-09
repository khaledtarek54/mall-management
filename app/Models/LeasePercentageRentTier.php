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

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    protected static function booted(): void
    {
        // Refuse OVERLAPPING bands, from any writer.
        //
        // An overlap double-charges the overlapping slice: a ladder of 0–500K@0% · 400K–900K@5% ·
        // 900K+@6% bills 31,000 on sales of 1,000,000 where the correct ladder bills 26,000,
        // because 400–500K is charged by two bands. An operator typing 400,000 instead of 500,000
        // as a floor would over-charge that tenant silently, for as long as the lease runs.
        //
        // **Gaps are deliberately allowed.** A gap is semantically identical to a 0%-rate band —
        // "no percentage rent on sales between X and Y" is a real deal shape, and it is also the
        // natural intermediate state while a ladder is being typed in. Refusing gaps would block a
        // legitimate structure to guard against a typo the overlap check already catches.
        static::saving(function (self $tier): void {
            $tier->assertNoOverlap();
        });
    }

    /** @throws \DomainException when this band overlaps another on the same lease */
    public function assertNoOverlap(): void
    {
        if (blank($this->lease_id)) {
            return;
        }

        $from = (float) $this->from_amount;
        $to = $this->to_amount !== null ? (float) $this->to_amount : INF;

        if ($to <= $from) {
            throw new \DomainException(__('admin.errors.percentage_rent_tier_inverted', [
                'from' => number_format($from, 2),
                'to' => number_format((float) $this->to_amount, 2),
            ]));
        }

        $clash = static::query()
            ->where('lease_id', $this->lease_id)
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->get()
            ->first(function (self $other) use ($from, $to): bool {
                $otherTo = $other->to_amount !== null ? (float) $other->to_amount : INF;

                // Half-open bands [from, to): touching edges (prev.to === next.from) are adjacent,
                // not overlapping, which is what a contiguous ladder looks like.
                return $from < $otherTo && (float) $other->from_amount < $to;
            });

        if ($clash) {
            throw new \DomainException(__('admin.errors.percentage_rent_tier_overlap', [
                'from' => number_format($from, 2),
                'to' => $this->to_amount !== null ? number_format($to, 2) : '∞',
                'other_from' => number_format((float) $clash->from_amount, 2),
                'other_to' => $clash->to_amount !== null ? number_format((float) $clash->to_amount, 2) : '∞',
            ]));
        }
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
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function ladderFor(Lease $lease): Collection
    {
        return $lease->relationLoaded('percentageRentTiers')
            ? $lease->percentageRentTiers->sortBy('from_amount')->values()
            : static::query()->where('lease_id', $lease->id)->orderBy('from_amount')->get();
    }
}
