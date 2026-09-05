<?php

namespace App\Support;

use App\Models\Asset;
use DomainException;

/**
 * A unit cannot measure more than the whole lettable part of the mall it sits in.
 *
 * Reported by the tester: on a property whose Leasable Area read 0, unit A-01 was saved at
 * 1,000 m² with no warning. Nothing errors when this happens — the unit register looks fine, and
 * the damage lands somewhere else entirely: `CamReconciliationService` apportions a recovery pool
 * by area, so one unit larger than the building takes a share above 100% and every other tenant is
 * under-charged, while the occupancy and GLA figures the mall is run on quietly stop meaning
 * anything.
 *
 * **A CEILING, not a sum.** This refuses only the impossible — a single unit bigger than the whole
 * lettable area. It deliberately does NOT refuse the case where the units ADD UP to more than the
 * property: measured areas drift, a re-survey lands one unit at a time, and refusing on the total
 * would lock an operator out of correcting the very rows that put it over — this codebase's
 * most-repeated fix-worse-than-the-bug shape. The running total is SHOWN instead, on the property's
 * Units tab, exactly as `AssetOwnersRelationManager` shows the ownership total it likewise cannot
 * refuse in one save.
 *
 * **Silent when the property has not stated a leasable area.** Null (and a legacy 0) mean "not
 * measured", and a ceiling of zero would refuse every unit on a property nobody has measured yet —
 * turning a missing figure into an unusable register. `AssetForm` now requires that figure, so this
 * only stays quiet for rows that predate the requirement or arrived through an import.
 *
 * Three doors write a unit's area and all three ask here: the create form, `RemeasureUnitService`,
 * and `UnitImporter`. Enumerated by grepping for the column, not from the diff that fixed the first.
 */
class AreaFitsTheProperty
{
    /** Does this area exceed what the property says it can let? */
    public static function exceeds(?float $area, ?Asset $asset): bool
    {
        $leasable = (float) ($asset?->leasable_area_sqm ?? 0);

        if ($leasable <= 0 || $area === null) {
            return false;
        }

        return round($area, 2) > round($leasable, 2);
    }

    /** @throws DomainException */
    public static function assert(?float $area, ?Asset $asset): void
    {
        if (! self::exceeds($area, $asset)) {
            return;
        }

        throw new DomainException(self::message($area, $asset));
    }

    public static function message(?float $area, ?Asset $asset): string
    {
        return __('admin.refusals.unit_area_exceeds_property', [
            'area' => number_format((float) $area, 2),
            'property' => $asset?->name ?? '—',
            'leasable' => number_format((float) ($asset?->leasable_area_sqm ?? 0), 2),
        ]);
    }
}
