<?php

namespace App\Console\Commands;

use App\Services\Search\RebuildSearchIndex;
use App\Support\SearchPolicy;
use Illuminate\Console\Command;

/**
 * Rebuild the denormalized `search_text` blob every searchable model carries.
 *
 * Safe to run repeatedly and safe to run in production: rows whose fold is
 * unchanged are read and skipped, not rewritten, and nothing it writes touches
 * `updated_at`, fires an observer, or lands in the activity log.
 *
 * Run it after changing `App\Support\Search\SearchText` (the fold itself) or a
 * model's `searchTextSources()` — neither of those touches rows already saved,
 * so until this runs, existing records are searchable only under the OLD rules.
 * NOT on the schedule: the `saving` hook keeps blobs current in normal operation,
 * and a nightly full-table rewrite to fix nothing is exactly the kind of job that
 * gets ignored when it eventually does report something.
 */
class RebuildSearchIndexCommand extends Command
{
    protected $signature = 'atriom:rebuild-search
        {--model=* : Rebuild only these models (short class name, e.g. Tenant). Defaults to every registered model.}';

    protected $description = 'Rebuild the search_text blob on every searchable record (idempotent).';

    public function handle(RebuildSearchIndex $rebuild): int
    {
        $models = $this->resolveModels();

        if ($models === null) {
            return self::FAILURE;
        }

        $this->info(sprintf('Rebuilding search text for %d model(s)…', count($models)));

        $total = 0;

        $counts = $rebuild($models, function (string $model, int $count) use (&$total): void {
            $total += $count;
            $this->line(sprintf(
                '  %-28s %s',
                class_basename($model),
                $count > 0 ? "{$count} rebuilt" : '<fg=gray>up to date</>',
            ));
        });

        $this->newLine();
        $this->info(sprintf(
            '%d record(s) rebuilt across %d model(s).',
            $total,
            count($counts),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, class-string>|null Null signals a bad --model argument.
     */
    protected function resolveModels(): ?array
    {
        $requested = (array) $this->option('model');

        if ($requested === []) {
            return SearchPolicy::INDEXED;
        }

        $byShortName = [];
        foreach (SearchPolicy::INDEXED as $model) {
            $byShortName[strtolower(class_basename($model))] = $model;
        }

        $resolved = [];

        foreach ($requested as $name) {
            $key = strtolower(class_basename($name));

            if (! isset($byShortName[$key])) {
                $this->error("Not a searchable model: {$name}");
                $this->line('Registered models: '.implode(', ', array_map(
                    fn (string $m): string => class_basename($m),
                    SearchPolicy::INDEXED,
                )));

                return null;
            }

            $resolved[] = $byShortName[$key];
        }

        return $resolved;
    }
}
