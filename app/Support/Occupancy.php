<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * What "occupied" means, in one place.
 *
 * **Why this exists.** The economic (area-weighted) occupancy of a set of units was computed in two
 * places — `Asset::areaOccupancyRate()` for one property, and the `MallStats` widget for the
 * operator's whole visible portfolio. Same formula, written twice, because the two need different
 * SCOPES and nobody had separated the scope from the definition.
 *
 * They agree today. The hazard is the day the definition moves: "occupied" gaining a state, or
 * space being excluded from the denominator. One of the two would be updated and the dashboard
 * would quietly disagree with the property list about the mall's headline number — with no error,
 * no test failing, and no way to tell which was right. That is the exact shape of defect this
 * codebase keeps finding, so the definition lives here and the callers bring their own query.
 *
 * **Area-weighted, not by headcount.** Letting the one 2,000 m² anchor moves revenue far more than
 * letting five kiosks, so this is the figure that tracks money. Parking and storage never appear:
 * a rentable item is not a unit, which is what keeps them out of the denominator structurally
 * (docs/benchmarks/yardi/09-yardi-space-and-parking.md).
 */
class Occupancy
{
    /**
     * Occupied and total leasable area for a set of units, and the percentage between them.
     *
     * `pct` is **null**, not 0, when there is no area to divide by — a property with no units
     * recorded is unknown, not empty, and reporting 0% would read as a mall nobody has let.
     *
     * @param  Builder<\App\Models\Unit>  $units
     * @return array{occupied_sqm: float, total_sqm: float, pct: float|null}
     */
    public static function forUnits(Builder $units): array
    {
        $total = (float) (clone $units)->sum('area_sqm');
        $occupied = (float) (clone $units)->where('status', 'occupied')->sum('area_sqm');

        return [
            'occupied_sqm' => round($occupied, 2),
            'total_sqm' => round($total, 2),
            'pct' => $total > 0 ? round($occupied / $total * 100, 1) : null,
        ];
    }
}
