<?php

namespace App\Jobs;

use App\Services\Accounting\LedgerPoster;
use App\Support\MorphMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Post / reconcile ONE document's general-ledger entry right after it changes — the
 * near-real-time layer over the daily `accounting:sync-ledger` sweep. Dispatched
 * afterCommit by `SyncsToLedgerOnChange` on the source's saved/deleted/restored events,
 * so the books are fresh within seconds instead of up to a day.
 *
 * It re-runs `LedgerPoster::sync()` (idempotent + lock-safe), so it can never double-book
 * and it composes cleanly with the sweep. Best-effort: a failure (GL not configured, a
 * closed period, a legacy un-postable doc) is logged, not retried into noise — the
 * scheduled sweep remains the guarantee. `ShouldBeUniqueUntilProcessing` collapses a burst
 * of saves of the same document into a single queued job, releasing the lock the instant the
 * worker starts — so a concurrent edit re-queues instead of being dropped (the row lock in
 * sync() then serialises them). Carries the source's class + key (not the model) so a
 * soft-deleted source still resolves (withTrashed) and voids its entry.
 */
class SyncDocumentToLedger implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Safety cap on the unique lock, in case a worker dies mid-job. */
    public int $uniqueFor = 120;

    public function __construct(public string $sourceType, public int|string $sourceId) {}

    public function uniqueId(): string
    {
        return $this->sourceType.':'.$this->sourceId;
    }

    public function handle(LedgerPoster $poster): void
    {
        // The payload carries a morph alias, so this must resolve rather than test `class_exists`.
        // A queued job dispatched before the map landed still carries an FQCN, which resolve()
        // also accepts — the queue can hold work written by the previous deploy.
        /** @var class-string<Model>|null $class */
        $class = MorphMap::resolve($this->sourceType);
        if ($class === null) {
            return;
        }

        $query = $class::query();
        // Include a soft-deleted source so its entry is voided (a deleted doc has no GL effect).
        if (method_exists(new $class, 'trashed')) {
            $query->withTrashed();
        }

        $model = $query->find($this->sourceId);
        if (! $model) {
            return; // hard-deleted between dispatch and run — nothing to reconcile
        }

        try {
            $poster->sync($model);
        } catch (\Throwable $e) {
            // Best-effort — the scheduled sweep re-attempts and surfaces persistent failures.
            Log::warning('Real-time ledger sync failed for '.$class.' #'.$this->sourceId.': '.$e->getMessage());
        }
    }
}
