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
     * `net_area_sqm` (point 20) rides with the survey when the key is PRESENT — null clears a net
     * the survey no longer states, an absent key leaves whatever the unit carries. It is not dated:
     * nothing apportions on it. The gross/net pair is asked ONCE, by `Unit::saving`, on the single
     * save below — a gross shrunk below a standing net is refused there in the reader's words and
     * the transaction rolls the dated row back with it; a second check here would be a copy.
     *
     * @param  array{effective_from?: string|\DateTimeInterface|null, reason?: string|null, net_area_sqm?: float|null}  $data
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
                // day for no reason, and make the register harder to read for nothing. The net
                // still lands: a survey that confirms the gross and corrects the net is a change.
                $this->writeHeadline($locked, null, $data);

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
            $this->writeHeadline($locked, $from->isFuture() ? null : round($newArea, 2), $data);

            return $row;
        });
    }

    /**
     * The unit's headline columns, in ONE save: the gross where the survey is in force today, and
     * the net where the survey stated one (the key present; null clears). One save, not two,
     * because the model re-asks the pair on every write — a gross shrunk below the standing net
     * would be refused before the net that re-states it had landed. The net is undated, so it
     * lands TODAY whatever the survey's effective date, and is judged against the gross in force
     * today — for a survey dated ahead that is the standing gross, not the survey's, and the
     * Remeasure modal compares the same way so the refusal reaches the operator as a field
     * message. (A future-dated survey's GROSS is never promoted into `area_sqm` by anything — no
     * sweep reads `unit_areas` forward; recorded as open in modules/01.)
     *
     * @param  array<string, mixed>  $data
     */
    private function writeHeadline(Unit $locked, ?float $gross, array $data): void
    {
        $attributes = $gross === null ? [] : ['area_sqm' => $gross];

        if (array_key_exists('net_area_sqm', $data)) {
            $net = $data['net_area_sqm'] === null ? null : round((float) $data['net_area_sqm'], 2);

            if ($net !== null && $net <= 0) {
                throw new DomainException(__('admin.errors.unit_area_not_positive'));
            }

            if (round((float) ($locked->net_area_sqm ?? 0), 2) !== (float) ($net ?? 0)) {
                $attributes['net_area_sqm'] = $net;
            }
        }

        if ($attributes !== []) {
            $locked->forceFill($attributes)->save();
        }
    }
}
