<?php

namespace App\Services\Search;

use App\Support\SearchPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds every `search_text` blob from its source row.
 *
 * The blob is normally maintained by `HasSearchText`'s `saving` hook, so this is
 * not a routine job. It exists for the three cases the hook cannot cover:
 *
 *  1. **Backfill.** The migration that adds the column calls this, so existing
 *     installs get a populated blob rather than a column of nulls that silently
 *     makes every record unfindable.
 *  2. **A changed fold.** `SearchText`'s normalization is versioned only by the
 *     code — adding a rule (a new hamza carrier, say) changes what the fold
 *     produces, and every stored blob is then a fold behind. Run this after
 *     touching `SearchText`.
 *  3. **A changed source list.** Adding a field to a model's
 *     `searchTextSources()` does nothing for rows already saved.
 *
 * Also the repair path for the one hole in the trait: a mass
 * `Model::query()->update()` fires no model events and leaves the blob stale.
 *
 * Soft-deleted rows ARE rebuilt. A trashed record still appears in restore flows
 * and in `withTrashed()` reports, and leaving it with a stale blob would make it
 * unfindable in exactly the situation where someone is hunting for it.
 */
class RebuildSearchIndex
{
    /**
     * @param  array<int, class-string>|null  $models  Defaults to the whole registry.
     * @param  (callable(class-string, int): void)|null  $onModelRebuilt  Progress reporting.
     * @return array<class-string, int> Rows rebuilt per model.
     */
    public function __invoke(?array $models = null, ?callable $onModelRebuilt = null): array
    {
        $counts = [];

        foreach ($models ?? SearchPolicy::INDEXED as $model) {
            $counts[$model] = $this->rebuildModel($model);

            if ($onModelRebuilt) {
                $onModelRebuilt($model, $counts[$model]);
            }
        }

        return $counts;
    }

    /**
     * @param  class-string  $model
     */
    protected function rebuildModel(string $model): int
    {
        /** @var Model $instance */
        $instance = new $model;

        // Defensive: the migration that adds the column calls this service, and
        // a partially-migrated database would otherwise fatal mid-migration
        // rather than skipping the table it has not reached yet.
        if (! Schema::hasColumn($instance->getTable(), 'search_text')) {
            return 0;
        }

        $query = $model::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        $rebuilt = 0;

        // chunkById, not chunk: `saveQuietly` below writes to the same table the
        // cursor is paging through, and offset-based chunking skips rows when the
        // underlying result set shifts. Also keeps memory flat on `invoices`,
        // which is the one table here that grows without bound.
        $query->chunkById(500, function ($records) use (&$rebuilt): void {
            foreach ($records as $record) {
                // No `@var Model&HasSearchText` here: HasSearchText is a trait, and an
                // intersection with a trait is not a type PHPStan can resolve. The guarantee that
                // every record in this loop has `refreshSearchText()` comes from
                // SearchPolicyConformanceTest, which fails the build if a model in
                // SearchPolicy::INDEXED does not use the trait.
                $before = $record->getAttribute('search_text');
                $record->refreshSearchText();

                // Skip the write when the fold is unchanged. On a re-run — which
                // is the common case, since this is safe to run repeatedly — that
                // turns a full rewrite of every table into a read-only pass.
                if ($record->getAttribute('search_text') === $before) {
                    continue;
                }

                // Quietly, and without touching `updated_at`: this must not fire
                // observers or write an activity-log entry. Rebuilding a derived
                // column is not something that happened to the record, and an
                // audit trail full of "search index rebuilt" is one nobody reads.
                // A moved `updated_at` would be worse than noise — it reorders
                // every "recently changed" list in the system.
                $record->timestamps = false;
                $record->saveQuietly();
                $rebuilt++;
            }
        });

        return $rebuilt;
    }
}
