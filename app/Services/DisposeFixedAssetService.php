<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use Illuminate\Support\Facades\DB;

/**
 * Disposes a fixed asset (module 23, Phase 2b): flips the register row to `disposed`
 * and records the terminal FixedAssetDisposal that journalizes the write-off (removes
 * cost + accumulated depreciation, recognises proceeds and the gain/loss). Lock-safe
 * and idempotent-guarded — a disposal is terminal, so a second attempt is rejected.
 */
class DisposeFixedAssetService
{
    /**
     * @param  array{disposed_on:mixed, proceeds?:mixed, proceeds_account?:string, notes?:?string}  $data
     */
    public function dispose(FixedAsset $asset, array $data): FixedAssetDisposal
    {
        return DB::transaction(function () use ($asset, $data) {
            // Lock + re-check inside the transaction (no double-dispose under a race).
            $locked = FixedAsset::whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active', 422);

            $locked->update([
                'status' => 'disposed',
                'disposed_on' => $data['disposed_on'],
            ]);

            return $locked->disposal()->create([
                'disposed_on' => $data['disposed_on'],
                'proceeds' => $data['proceeds'] ?? 0,
                'proceeds_account' => ($data['proceeds_account'] ?? 'cash') === 'bank' ? 'bank' : 'cash',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);
        });
    }
}
