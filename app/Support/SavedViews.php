<?php

namespace App\Support;

use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use Filament\Facades\Filament;
use Filament\Tables\Table;

/**
 * Which resource lists offer "save this view", and the rule that decides.
 *
 * ## Why a rule and not a list
 *
 * The feature shipped on seven lists of sixty-six, chosen by hand, and there was nothing to say
 * whether the other fifty-nine were a decision or an oversight. A hand-picked list also goes stale
 * in the one direction nobody notices: a resource that grows its fourth filter next month does not
 * grow a way to save the combination, and the person who added the filter has no reason to think
 * about it.
 *
 * So the rule is derived from the thing that actually makes a list worth bookmarking — **how many
 * filters it carries** — and `SavedViewsCoverageConformanceTest` fails the build when a list on the
 * wrong side of it does not match. Adding a third filter to a resource now tells you to mount the
 * trait.
 *
 * ## Why three
 *
 * `SavesTableViews` exists because *"active leases in this mall whose option window shuts this
 * quarter"* was five controls rebuilt every morning. One or two controls is not a chore; three is
 * where rebuilding it by hand starts costing more than naming it. Measured against the panel as it
 * stands, the threshold divides sixty-six lists into thirty-four that carry a standing question
 * (leases at fourteen filters, work orders at ten, tenant requests at nine) and thirty-two that are
 * catalogues someone opens to change one row.
 *
 * A view stores more than filters — search, sort, tab and the column layout — so the threshold is a
 * floor rather than the whole truth. {@see ALWAYS} is the opt-in for a list below it whose value is
 * in one of the others, and {@see NEVER} the opt-out above it. Both take a reason, because "this
 * one is different" is not reviewable without one.
 */
final class SavedViews
{
    /**
     * The number of filters at which a list is worth bookmarking.
     *
     * Changing this is a product decision, not a tuning knob: it moves what the panel offers on
     * every list at once, and the conformance gate will name every resource that has to move with
     * it.
     */
    public const THRESHOLD = 3;

    /**
     * Lists that offer saved views despite carrying fewer filters than {@see THRESHOLD}.
     *
     * @var array<class-string, string> resource => why
     */
    public const ALWAYS = [];

    /**
     * Lists that do NOT offer saved views despite carrying enough filters.
     *
     * @var array<class-string, string> resource => why
     */
    public const NEVER = [];

    /** Should this resource's list page offer the saved-views menu? */
    public static function offeredBy(string $resource): bool
    {
        if (array_key_exists($resource, self::NEVER)) {
            return false;
        }

        if (array_key_exists($resource, self::ALWAYS)) {
            return true;
        }

        return self::filterCount($resource) >= self::THRESHOLD;
    }

    /** Does this resource's list page actually mount the trait? */
    public static function mountedBy(string $resource): bool
    {
        $page = ($resource::getPages()['index'] ?? null)?->getPage();

        return $page !== null
            && in_array(SavesTableViews::class, class_uses_recursive($page), true);
    }

    /**
     * How many filters this resource's table declares.
     *
     * Built through the resource's real `table()` on a real list-page instance, because a filter
     * can be added conditionally and counting them from the source would count the ones that are
     * never offered. Filament needs a current panel for some filter option lookups; a resource that
     * cannot be built at all counts as zero rather than throwing, so the gate reports it as
     * "should not offer" instead of failing for an unrelated reason.
     */
    public static function filterCount(string $resource): int
    {
        $page = ($resource::getPages()['index'] ?? null)?->getPage();

        if ($page === null) {
            return 0;
        }

        try {
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            return count($resource::table(Table::make(new $page))->getFilters());
        } catch (\Throwable) {
            return 0;
        }
    }
}
