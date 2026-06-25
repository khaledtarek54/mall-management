<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves the operator-side recipients for a property asset's notifications:
 * users holding one of the given property-team roles AND assigned to the asset,
 * unioned with every super_admin.
 *
 * Super_admins are always included — platform owners see all property activity,
 * not merely as a fallback when nobody is assigned. Centralises the recipient
 * query shared by the maintenance, sales-declaration and SLA-breach fan-outs so
 * the rule lives in exactly one place.
 */
class AssetStaffRecipients
{
    /**
     * @param  array<int, string>  $roles  Property-team roles for this notification type; super_admin is added automatically.
     * @return Collection<int, User>
     */
    public function for(?int $assetId, array $roles): Collection
    {
        $assigned = $assetId
            ? User::query()
                ->role($roles)
                ->whereHas('assignedAssets', fn ($q) => $q->where('assets.id', $assetId))
                ->get()
            : collect();

        return $assigned
            ->merge(User::query()->role('super_admin')->get())
            ->unique('id')
            ->values();
    }

    /**
     * Jawad owner users for a property — for oversight alerts (e.g. an SLA
     * breach). Scoped by the asset_owner relationship.
     *
     * @return Collection<int, User>
     */
    public function owners(?int $assetId): Collection
    {
        if (! $assetId) {
            return collect();
        }

        return User::query()
            ->whereHas('ownedAssets', fn ($q) => $q->where('assets.id', $assetId))
            ->get();
    }
}
