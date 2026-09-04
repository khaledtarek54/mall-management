<?php

namespace App\Models\Concerns;

use App\Models\Unit;

/**
 * A record whose facility zone (module 30) is INHERITED from the unit it is about.
 *
 * `TenantRequest` and `FacilityWorkOrder` both derived `area_id` from `units.area_id` in a
 * `creating` hook and neither ever looked again, so correcting the unit on the Edit page left the
 * zone pointing at the shop the record used to be about. Silently, and under a field the request
 * form renders DISABLED with the placeholder `admin.fields.area_auto` — "auto" — beside a comment
 * saying "the derivation owns the value, the form only surfaces it". It did not.
 *
 * **The zone is routing, not decoration.** `NotifyAreaSupervisorsService` tells that zone's
 * supervisors; both lists carry an `area.name` column and an area filter; and
 * `FacilityWorkOrder::booted()` copies a request's zone onto every corrective work order raised
 * from it, so a stale request sends a technician to the wrong part of the mall.
 *
 * Measured on the QA baseline database (`mall_management_qa`, 2026-09-04): **10 of 10 tenant
 * requests and 3 of 3 unit-bearing work orders carry a zone that disagrees with their unit's** —
 * every one of them null against a unit in zone 1, because the seeder zones the units after the
 * records exist. That is the same cause read from the other side: the zone is derived once and
 * nothing re-derives it. This trait closes the door the operator actually walks through (the unit
 * is corrected); a unit being RE-ZONED underneath existing records is a cascade over another
 * table's write and is deliberately not attempted here.
 *
 * **It re-inherits only what it gave.** The creating hook's own rule is that "an explicitly-set
 * area_id is never overridden (a caller may target a zone directly)", and that has to keep holding
 * after intake — an area-scoped PPM work order carries the SERVICE PLAN's zone, not its unit's. So
 * the zone moves only when the stored one is the OLD unit's, or is nothing at all: a null was
 * stated by nobody, which is not the same as a zone somebody chose.
 *
 * Assignment is deliberately NOT re-derived. Intake routes an unassigned record to a zone's single
 * supervisor; re-running that on a unit correction would take a live job away from whoever is
 * already working it, which is a decision and not a derivation.
 */
trait InheritsAreaFromUnit
{
    /**
     * On `updating`, deliberately NOT on `saving`.
     *
     * A trait's boot method runs during `bootTraits()`, which is BEFORE the class's own `booted()`
     * — so a `saving` listener registered here would fire ahead of every `saving` hook the model
     * itself declares, including `TenantRequest`'s terminal freeze (which refuses a commercial
     * write on a closed or cancelled request). On `updating` all of those have already run and
     * already refused, so this can only ever touch a record the model itself accepted. Same trap,
     * and the same reasoning, that `RecordsBankAccount` records for its own boot hook.
     */
    public static function bootInheritsAreaFromUnit(): void
    {
        static::updating(function ($record): void {
            $record->reinheritAreaFromUnit();
        });
    }

    /** The zone a unit sits in — the ONE definition, read by the creating hooks and by this one. */
    protected static function zoneOfUnit(int|string|null $unitId): ?int
    {
        if ($unitId === null || $unitId === '') {
            return null;
        }

        $areaId = Unit::whereKey($unitId)->value('area_id');

        return $areaId === null ? null : (int) $areaId;
    }

    /** Move the zone with the unit, but only where the zone was this trait's to give. */
    protected function reinheritAreaFromUnit(): void
    {
        if (! $this->isDirty('unit_id')) {
            return;
        }

        // Somebody stated a zone in this same write — theirs wins. `isDirty`, not a value
        // comparison: "the operator also moved the zone" and "the zone happens to differ" are
        // different facts, and only the first is a statement.
        if ($this->isDirty('area_id')) {
            return;
        }

        $stored = $this->area_id === null ? null : (int) $this->area_id;

        if ($stored !== null && $stored !== static::zoneOfUnit($this->getOriginal('unit_id'))) {
            return;
        }

        $this->area_id = static::zoneOfUnit($this->unit_id);
    }
}
