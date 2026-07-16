<?php

namespace App\Support;

use App\Models\Equipment;

/**
 * Option list for an equipment Select (module 26), shared by the plan and work-order forms.
 *
 * Extracted rather than copied: both forms need the same two non-obvious rules, and a
 * near-identical private copy in each is how they quietly stop agreeing.
 */
class EquipmentPicker
{
    /**
     * Active machines in the given property — **plus the record's own stored one**, even if
     * since deactivated or soft-deleted.
     *
     * Both halves are load-bearing:
     *
     *  - The property is clamped (`TenantScope::clampAssetId`) because a form's `asset_id`
     *    is client-supplied — `->live()`, and the Select is enabled in All-Properties mode —
     *    so keying the query on the raw value enumerates an invisible property's machines.
     *
     *  - The stored value is always included because Filament derives an `in:` rule from a
     *    Select's options and validates the CURRENT value against it
     *    (`Select::getInValidationRuleValues()` → blank label → `Rule::in([])`, which always
     *    fails). Filtering to `->active()` alone meant that decommissioning a machine made
     *    every record naming it permanently unsavable — a plan couldn't even be deactivated.
     *
     * @param  mixed  $assetIdFromForm  the raw, client-supplied asset_id
     * @param  int|null  $currentId  the record's stored equipment_id, if editing
     * @return array<int,string>
     */
    public static function options(mixed $assetIdFromForm, ?int $currentId = null): array
    {
        $assetId = TenantScope::clampAssetId($assetIdFromForm);

        if ($assetId === null) {
            return [];
        }

        $options = Equipment::query()
            ->where('asset_id', $assetId)
            ->active()
            ->orderBy('code')
            ->get();

        if ($currentId !== null && ! $options->contains('id', $currentId)) {
            // withTrashed: a soft-deleted machine is dropped by the default scope, and is
            // one of the two states that used to deadlock the record.
            $current = Equipment::withTrashed()->whereKey($currentId)->first();

            if ($current !== null) {
                $options->push($current);
            }
        }

        return $options->mapWithKeys(fn (Equipment $e) => [$e->id => $e->label()])->all();
    }
}
