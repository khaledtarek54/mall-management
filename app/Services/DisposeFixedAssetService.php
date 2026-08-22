<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Support\PostingDate;
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
        // Proceeds can't be negative — a tampered negative would inflate the loss and
        // yield an unbalanced (unpostable) disposal entry. (minValue is client-side only.)
        $proceeds = round((float) ($data['proceeds'] ?? 0), 2);
        abort_unless($proceeds >= 0, 422);

        // `disposed_on` becomes the write-off entry's GL entry_date
        // (FixedAssetDisposalJournalizer), so a date in a CLOSED period must be refused
        // HERE — the same guard the AP/AR/custody/stock services enforce.
        //
        // Without it the divergence is silent and one-directional: the register row commits
        // (status = disposed, "Disposed ✓" on screen) while the journal entry is refused
        // inside the best-effort SyncDocumentToLedger job, which logs rather than retries.
        // Furniture & Equipment then goes on carrying an asset the company has sold, its
        // Accumulated Depreciation is never cleared, and the gain or loss never reaches the
        // P&L — for the one event in this module that is TERMINAL and cannot be re-run.
        //
        // A disposal is exactly the operation likely to be dated back: the sale happened
        // before the paperwork reached accounting.
        //
        // Refused BEFORE the transaction, so nothing is written. A MISSING period stays
        // legal (assertOpen refuses only a closed one), so installs without a chart of
        // accounts are unaffected.
        $disposedOn = PostingDate::assertOpen($data['disposed_on'] ?? null, 'disposed_on')->toDateString();

        return DB::transaction(function () use ($asset, $data, $proceeds, $disposedOn) {
            // Lock + re-check inside the transaction (no double-dispose under a race).
            $locked = FixedAsset::whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active', 422);

            $locked->update([
                'status' => 'disposed',
                'disposed_on' => $disposedOn,
            ]);

            return $locked->disposal()->create([
                'disposed_on' => $disposedOn,
                'proceeds' => $proceeds,
                // Not clamped — see GrantCustodyService.
                'proceeds_account' => $data['proceeds_account'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);
        });
    }
}
