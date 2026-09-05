<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\UnitArea;
use App\Support\AreaFitsTheProperty;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Record a new measured area for a unit, from a date — without rewriting what came before.
 *
 * A re-survey, a demise, or a fit-out that moved a wall changes what a shop measures. Until now that
 * was an edit to `units.area_sqm`, and every past period recomputed from it moved with it: last
 * year's CAM reconciliation, re-run today, apportioned the pool on this year's number. The tenant's
 * share of a year they have already been billed for would change.
 *
 * **Closes the row in force and opens the next**, exactly as `ChargeScheduleService` does for money.
 * The old area stays true for the months it was true for.
 *
 * `units.area_sqm` is updated in the same transaction. It is the denormalised CURRENT measurement —
 * the same relationship `leases.base_rent_monthly` has to the dated charge rows — and this service
 * is the only thing that may move it.
 */
class RemeasureUnitService
{
    /**
     * @param  array{effective_from?: string|\DateTimeInterface|null, reason?: string|null}  $data
     */
    public function record(Unit $unit, float $newArea, array $data = []): UnitArea
    {
        if ($newArea <= 0) {
            throw new DomainException(__('admin.errors.unit_area_not_positive'));
        }

        // The second door onto `units.area_sqm` — the create form is the first. A re-survey can put
        // a shop above the whole lettable area just as easily as a mistyped creation can, and this
        // is the path that exists precisely to change the number afterwards.
        AreaFitsTheProperty::assert($newArea, $unit->asset);

        $from = isset($data['effective_from']) && $data['effective_from']
            ? CarbonImmutable::parse($data['effective_from'])->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        return DB::transaction(function () use ($unit, $newArea, $from, $data) {
            // Lock the unit: two operators recording a remeasurement at once must not both close
            // the same open row and leave two of them open, which would make `areaOn()` ambiguous.
            $locked = Unit::query()->lockForUpdate()->findOrFail($unit->id);

            $current = $locked->areas()
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $from->toDateString()))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()))
                ->orderByRaw('effective_from IS NULL, effective_from DESC')
                ->first();

            if ($current && round((float) $current->area_sqm, 2) === round($newArea, 2)) {
                // Nothing changed. Opening an identical row would put a second answer on the same
                // day for no reason, and make the register harder to read for nothing.
                return $current;
            }

            if ($current) {
                if ($current->effective_from !== null
                    && CarbonImmutable::instance($current->effective_from)->startOfDay()->greaterThanOrEqualTo($from)) {
                    // The date is at or before the row it would close, which would leave a row with
                    // no days in it and two measurements claiming the same period.
                    throw new DomainException(__('admin.errors.unit_area_not_after_current'));
                }

                $current->update(['effective_to' => $from->subDay()->toDateString()]);
            }

            $row = UnitArea::create([
                'unit_id' => $locked->id,
                'area_sqm' => round($newArea, 2),
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'reason' => $data['reason'] ?? null,
                'recorded_by_user_id' => Auth::id(),
            ]);

            // The headline column follows only when the new measurement is in force TODAY. A
            // remeasurement dated in the future must not make the current area read as something
            // the unit does not yet measure.
            if (! $from->isFuture()) {
                $locked->forceFill(['area_sqm' => round($newArea, 2)])->save();
            }

            return $row;
        });
    }
}
