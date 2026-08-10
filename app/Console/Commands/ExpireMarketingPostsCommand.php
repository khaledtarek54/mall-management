<?php

namespace App\Console\Commands;

use App\Models\MarketingPost;
use App\Services\MarketingPost\ArchiveMarketingPostService;
use Illuminate\Console\Command;

/**
 * Archive published posts whose display window has closed.
 *
 * A shopper feed that nobody sweeps fills with expired offers, and an expired offer is worse than
 * no offer — the shopper walks to the shop and is told the discount ended a fortnight ago. The
 * `liveFor()` predicate already hides them at read time, so this is not what keeps the feed
 * correct; it is what keeps the operator's REGISTER honest, so "published" means "running" when
 * marketing looks at the list.
 *
 * Idempotent and lock-safe, per the scheduled-scan invariant: each row is re-checked inside its
 * own locked transaction (in {@see ArchiveMarketingPostService}), so two overlapping runs cannot
 * both act on the same post, and the service's already-archived early return absorbs the race.
 */
class ExpireMarketingPostsCommand extends Command
{
    protected $signature = 'marketing:expire-posts {--dry-run : Print what would change without writing}';

    protected $description = 'Archive published marketing posts whose display window has closed (module 36).';

    public function handle(ArchiveMarketingPostService $archiver): int
    {
        $now = now();

        // The same COALESCE(display, validity) rule the model's predicate uses — expressed in SQL
        // because the sweep must not load every published post to ask the question.
        $candidates = MarketingPost::query()
            ->where('status', MarketingPost::STATUS_PUBLISHED)
            ->whereRaw('COALESCE(display_until, ends_at) IS NOT NULL')
            ->whereRaw('COALESCE(display_until, ends_at) < ?', [$now]);

        $count = (clone $candidates)->count();

        if ($count === 0) {
            $this->info('No published marketing posts past their window.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would archive {$count} marketing post(s):");
            // withTrashed on the eager load: `Asset` soft-deletes, so a post in a retired mall
            // would otherwise resolve `asset` to null and print "—" for the one field that says
            // WHICH mall the operator is being told about. Loading the trashed row names it
            // properly — and, because `asset_id` is NOT NULL behind a cascading FK, it also makes
            // the relation genuinely non-null, so the nullsafe below is gone rather than
            // baselined as a false positive.
            (clone $candidates)
                ->with(['asset' => fn ($q) => $q->withTrashed()->select('id', 'code')])
                ->get(['id', 'asset_id', 'title', 'ends_at', 'display_until'])
                ->each(function (MarketingPost $post): void {
                    $this->line(sprintf(
                        '  #%d %s · %s · ended %s',
                        $post->id,
                        $post->asset->code,
                        $post->title,
                        ($post->display_until ?? $post->ends_at)?->format('Y-m-d H:i') ?? '—',
                    ));
                });

            return self::SUCCESS;
        }

        $archived = 0;

        foreach ((clone $candidates)->select('id')->get() as $row) {
            $post = MarketingPost::find($row->id);

            if ($post === null) {
                continue;
            }

            // Re-check under the lock happens inside the service; a post someone re-dated between
            // the scan and now is skipped here so the sweep never archives something still live.
            if (! $post->hasExpired($now) || ! $post->isPublished()) {
                continue;
            }

            $archiver->handle($post, null, 'expired');
            $archived++;
        }

        $this->info("Archived {$archived} marketing post(s).");

        return self::SUCCESS;
    }
}
