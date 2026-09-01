<?php

namespace App\Console\Commands;

use App\Models\AssistantDocChunk;
use App\Support\Assistant\DocCorpus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the assistant's documentation index from the markdown in `docs/`.
 *
 * **A DEPLOY step, not a scheduled one** — and that is a correction to the original design, which
 * called for a nightly rebuild. These files change when the repository changes and at no other
 * time, so a nightly run would rewrite an identical table 365 times a year and tell nobody
 * anything. It sits in `deploy.sh` beside `atriom:rebuild-search`, which is there for exactly the
 * same reason.
 *
 * **Rebuilt wholesale inside a transaction.** A partial index is worse than a stale one: the
 * assistant would answer confidently from whichever half survived, with nothing on screen to say
 * the other half was missing.
 */
class RebuildAssistantIndexCommand extends Command
{
    protected $signature = 'atriom:rebuild-assistant-index {--path= : Read from this docs directory instead}';

    protected $description = "Rebuild the assistant's documentation index from docs/";

    public function handle(): int
    {
        $docs = $this->option('path') ?: base_path('docs');

        if (! is_dir($docs)) {
            // Reported rather than silent. A box that shipped without `docs/` would otherwise show
            // an assistant that has quietly lost a whole tier of answers, and nothing would say so.
            $this->error("No documentation directory at {$docs} — the assistant's third tier will be empty.");

            return self::FAILURE;
        }

        $chunks = DocCorpus::chunks($docs);

        if ($chunks === []) {
            $this->error('Read the documentation and found nothing to index. Refusing to empty the table.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($chunks): void {
            AssistantDocChunk::query()->delete();

            foreach (array_chunk($chunks, 200) as $batch) {
                AssistantDocChunk::insert(array_map(
                    fn (array $row): array => $row + ['created_at' => now(), 'updated_at' => now()],
                    $batch,
                ));
            }
        });

        $byLocale = collect($chunks)->countBy('locale');

        $this->info(sprintf(
            'Indexed %d sections from %d files (%s).',
            count($chunks),
            count(array_unique(array_column($chunks, 'path'))),
            $byLocale->map(fn (int $n, string $l): string => "{$l}: {$n}")->implode(', '),
        ));

        return self::SUCCESS;
    }
}
