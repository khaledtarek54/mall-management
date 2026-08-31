<?php

namespace App\Support;

use App\Models\CamExpensePool;
use App\Models\Lease;
use Illuminate\Support\Facades\DB;

/**
 * The ledger accounts a lease's recovery pools actually contain.
 *
 * The exclusion picker offers these rather than the whole chart of accounts. An account no pool on
 * this property holds excludes NOTHING — `netForPoolAccounts()` intersects with the pool's own set
 * — so offering it would produce a clause that looks configured and carves out nothing, which is
 * the inert-mechanism failure this project treats as worse than an unbuilt one.
 *
 * Scoped to the lease's PROPERTY, because a recovery pool belongs to one.
 */
class CamPoolAccountIds
{
    /** @return list<int> */
    public static function forLease(?Lease $lease): array
    {
        $assetId = $lease?->unit?->asset_id;

        if ($assetId === null) {
            return [];
        }

        return DB::table('cam_pool_accounts')
            ->join('cam_expense_pools', 'cam_expense_pools.id', '=', 'cam_pool_accounts.cam_expense_pool_id')
            ->where('cam_expense_pools.asset_id', $assetId)
            ->distinct()
            ->pluck('cam_pool_accounts.ledger_account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    public static function forPool(CamExpensePool $pool): array
    {
        return $pool->ledgerAccounts->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
