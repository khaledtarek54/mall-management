<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One band of a percentage-rent breakpoint ladder. See the migration for why a ladder exists.
 */
#[DeletionAllowed(reason: 'parent-managed: one band of a lease\'s breakpoint ladder, edited from the lease')]
#[PropertyOwned(via: 'lease.unit')]
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

    /**
     * @throws \DomainException when this band overlaps another on the same lease, runs backwards,
     *                          or carries a breakpoint / rate outside its legal range
     */
    public function assertNoOverlap(): void
    {
        if (blank($this->lease_id)) {
            return;
        }

        $from = (float) $this->from_amount;
        $to = $this->to_amount !== null ? (float) $this->to_amount : INF;

        // Bounds. The relation manager carried minValue(0) on the breakpoints and
        // minValue(0)->maxValue(100) on the rate, and nothing stood behind it — so an import, the
        // console or a crafted submit could write any of them (validation sweep, 2026-08-11).
        //
        // The RATE is the one that matters: a negative rate produces a percentage-rent "charge"
        // that is really a credit, raised through the same immediate-invoice path as a real
        // overage (PercentageRentCalculationService), so the tenant is credited by a document that
        // says charge. Above 100% bills more percentage rent than the tenant sold.
        //
        // A negative BREAKPOINT is milder but not harmless: a first band starting below zero
        // charges the percentage from the tenant's very first pound of sales, quietly deleting the
        // natural break the whole ladder exists to express.
        if ($from < 0 || ($this->to_amount !== null && (float) $this->to_amount < 0)) {
            throw new \DomainException(__('admin.errors.percentage_rent_tier_negative_breakpoint'));
        }

        $rate = (float) $this->rate;
        if ($rate < 0 || $rate > 100) {
            throw new \DomainException(__('admin.errors.percentage_rent_tier_rate_out_of_range', [
                'rate' => number_format($rate, 2),
            ]));
        }

        if ($to <= $from) {
            throw new \DomainException(__('admin.errors.percentage_rent_tier_inverted', [
                'from' => number_format($from, 2),
                'to' => number_format((float) $this->to_amount, 2),
            ]));
        }

        $clash = self::clashingBand($this->lease_id, $from, $to, $this->exists ? $this->getKey() : null);

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
     * The band this one would overlap, if any — the same question the save guard asks, asked
     * BEFORE the save so a form can answer at the field instead of throwing.
     *
     * The guard alone refused correctly and told nobody: a `DomainException` from a model inside a
     * Filament modal is turned into a redirect-back, the modal closes, and the operator sees the
     * page reload with no message. Reported from the panel twice as "nothing happened" — which is
     * worse than accepting the row, because a silent refusal cannot be acted on.
     *
     * Half-open bands `[from, to)`: touching edges (prev.to === next.from) are ADJACENT, not
     * overlapping, which is what a contiguous ladder looks like.
     */
    public static function clashingBand(?int $leaseId, float $from, float $to, ?int $ignoreId = null): ?self
    {
        if ($leaseId === null) {
            return null;
        }

        return static::query()
            ->where('lease_id', $leaseId)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->first(function (self $other) use ($from, $to): bool {
                $otherTo = $other->to_amount !== null ? (float) $other->to_amount : INF;

                return $from < $otherTo && (float) $other->from_amount < $to;
            });
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
