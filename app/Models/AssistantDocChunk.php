<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One heading-sized piece of the operator-facing documentation.
 *
 * Rebuilt wholesale by `atriom:rebuild-assistant-index`; see the migration for why it is a table.
 */
#[PortfolioShared] // documentation about the system, identical in every mall
#[DeletionAllowed(reason: 'Parent-managed: a build artefact rebuilt wholesale from the repository by `atriom:rebuild-assistant-index`. Deleting rows IS how the rebuild works, and nothing an operator did is lost.')]
class AssistantDocChunk extends Model
{
    protected $fillable = ['path', 'locale', 'heading', 'url', 'excerpt', 'search_blob'];

    /**
     * Chunks whose blob contains EVERY word — a much stricter test than the screen corpus's
     * weighted overlap, and deliberately so.
     *
     * The guides are a small, curated vocabulary where partial overlap is meaningful. This is
     * hundreds of pages of prose in which almost every common word appears somewhere, so scoring
     * partial matches would rank the longest chapter first for every question ever asked. Requiring
     * all of them is what makes a hit mean something.
     *
     * Folded on BOTH sides — the query through `SearchText::words()`, the blob at index time —
     * because folding one side matches nothing, which is the rule this codebase states for every
     * other search it has.
     *
     * @param  Builder<static>  $query
     * @param  array<int, string>  $words
     * @return Builder<static>
     */
    public function scopeMatchingAll(Builder $query, array $words): Builder
    {
        foreach ($words as $word) {
            $folded = self::stem(SearchText::normalize($word));

            if ($folded === '') {
                continue;
            }

            $query->where('search_blob', 'like', '%'.$folded.'%');
        }

        return $query;
    }

    /**
     * A conservative stem, so "bounces" finds the paragraph that says "bounced".
     *
     * This is NOT a stemmer and does not try to be. It strips one common English inflection and
     * the Arabic definite article, and it is safe here for a structural reason that does not hold
     * anywhere else in this codebase: the blob is matched with `LIKE %stem%`, so a SHORTER stem
     * can only ever match MORE rows, never fewer. Over-stripping costs precision, which the
     * all-words AND then takes back; under-stripping costs the answer entirely, which is what
     * "what happens when a cheque bounces" returned before this.
     *
     * Deliberately not applied to the screen corpus, which matches whole words against a curated
     * vocabulary where "lease" and "leases" are not the same signal.
     */
    public static function stem(string $word): string
    {
        // «الفواتير» → «فواتير». Arabic attaches its article, so a reader typing the bare noun and
        // a document using the definite form never meet without this.
        if (mb_strlen($word) > 4 && str_starts_with($word, 'ال')) {
            $word = mb_substr($word, 2);
        }

        // ORDER MATTERS, and a separate `es` rule was removed rather than reordered. Stripping
        // `es` turns "bounces" into "bounc" and "invoices" into "invoic" — still matching, because
        // this is a substring search, but needlessly loose. This domain's plurals are almost all
        // of nouns already ending in `e` (invoice, lease, expense, balance, charge), so the plain
        // `s` rule is both simpler and more precise for the words actually typed here.
        foreach (['ing' => 6, 'ed' => 5, 's' => 4] as $suffix => $minimum) {
            if (mb_strlen($word) > $minimum && str_ends_with($word, $suffix)) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
            }
        }

        return $word;
    }
}
