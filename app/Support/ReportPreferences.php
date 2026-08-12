<?php

namespace App\Support;

use App\Models\ReportPreference;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * What each operator last chose on each report, so they stop re-choosing it (RP-02).
 *
 * Eltizam runs several malls. An accountant who works one of them re-picked it on every report,
 * every visit, and the pick was never wrong — just repeated. That is the standing preference this
 * remembers.
 *
 * ## Dates are NOT remembered, and that is the design
 *
 * The obvious implementation remembers every parameter. It should not, and this is the one decision
 * in this class worth arguing about.
 *
 * A remembered **as-of date** means an operator opens the AR ageing three weeks later and reads
 * totals struck at a date they did not choose and did not notice. The date is on screen — in the
 * filter bar, and in the page title on several of these reports — but the totals are what get read,
 * quoted in a meeting and pasted into an email. This is the same failure as a filter that updates
 * without clearing its cache: **invisible, and it looks authoritative.**
 *
 * "What I picked last time" is also least likely to be right for a date. A period, an as-of, a range
 * — these are the parameters an operator changes precisely because the answer they want has moved.
 * Property, bucket and account are the opposite: they describe which slice of the business this
 * person is responsible for, and that does not change between visits.
 *
 * So {@see VOLATILE} names every date-like parameter and they are never stored. A report always
 * opens at its own default — today, this period — and remembers only the slice.
 *
 * ## A URL still wins
 *
 * {@see restore()} fills in only what the query string did not supply, so a shared or bookmarked
 * link means exactly what it says. A saved view (RP-05) that pinned Cairo Festival must not be
 * silently re-pointed at whichever mall the recipient last looked at.
 */
class ReportPreferences
{
    /**
     * Parameters that are never remembered, because a stale one is read as current.
     *
     * @var array<int, string>
     */
    public const VOLATILE = ['asOf', 'from', 'to', 'period', 'year', 'date'];

    /** Store this operator's current choices for this report, minus the volatile ones. */
    public static function remember(Page $page): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $parameters = collect(ReportParameters::snapshot($page))
            ->except(self::VOLATILE)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        if ($parameters === []) {
            // Nothing worth remembering — and clearing the row is right rather than leaving a stale
            // one, because "I deselected the property" is itself the preference.
            ReportPreference::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('report', $page::class)
                ->delete();

            return;
        }

        ReportPreference::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'report' => $page::class],
            ['parameters' => $parameters],
        );
    }

    /**
     * Apply what this operator last chose — but only where the URL said nothing.
     *
     * Returns the parameters actually applied, so a caller can tell "restored" from "defaulted".
     *
     * @return array<string, mixed>
     */
    public static function restore(Page $page): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        $stored = ReportPreference::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('report', $page::class)
            ->value('parameters');

        if (! is_array($stored) || $stored === []) {
            return [];
        }

        // A URL beats a memory. An explicit `?assetId=3` — a bookmark, a shared link, a saved view
        // being opened — means what it says, and must not be overwritten by whichever mall this
        // user happened to look at last.
        $applicable = collect($stored)
            ->except(self::VOLATILE)
            ->reject(fn ($value, string $key) => request()->query->has($key))
            ->all();

        if ($applicable === []) {
            return [];
        }

        ReportParameters::apply($page, $applicable);

        return $applicable;
    }
}
