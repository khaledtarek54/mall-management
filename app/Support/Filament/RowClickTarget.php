<?php

namespace App\Support\Filament;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * WHAT CLICKING A ROW OPENS — one answer for every table in every panel.
 *
 * Filament's own answer is `['view', 'edit']`, in that order, in two places:
 * `ListRecords::makeTable()` and `InteractsWithRelationshipTable::makeTable()`.
 * Both prefer VIEW, and both are silent about it — so which page a row opens is
 * decided not by anything a resource states but by whether somebody happened to
 * register a `view` page for it. Four admin resources have one (Tenants,
 * Announcements, RentableItems, OwnerStatementRuns) and 62 do not, so the same
 * click meant "read this" on four lists and "work on this" on the other sixty-two,
 * with nothing on screen to tell them apart.
 *
 * The order here is `['edit', 'view']`. It is the same rule the panel already
 * follows everywhere else — "the list FINDS; the record ACTS" (see
 * App\Support\RowActionPolicy): an operator clicks a row to get to the record and
 * do something to it, and the eye button beside it is still there for a read.
 *
 * VIEW IS NOT DROPPED, IT IS THE FALLBACK, and it is answered PER RECORD, not per
 * resource. A viewer who holds `tenants.view` and not `tenants.edit` lands on the
 * View page; so does a SENT announcement, whose `canEdit()` refuses it while the
 * unsent one beside it opens for editing. A resource with no edit page at all
 * (OwnerStatementRuns) is unchanged.
 *
 * Registered ONCE from TableDefaults, never per table: `makeTable()` opens by
 * calling `Table::make()`, which is where `configureUsing` callbacks run — so ours
 * is installed before the rest of that method, which only installs Filament's
 * version `if (! $table->hasCustomRecordUrl())`. Ours therefore stands, and the
 * sixty-seventh resource inherits it instead of inheriting whatever its author
 * remembered. A resource that genuinely wants something else still wins,
 * because its own `XTable::configure()` runs after.
 *
 * THE BLAST RADIUS IS FOUR RESOURCES, AND SAYING SO IS THE POINT. Filament's loop
 * `continue`s past an action that resolves to no URL (`ListRecords.php:188`), and a
 * `ViewAction` on a resource with no `view` PAGE resolves to none — so on the other
 * sixty-two lists it already fell through to `edit`, which is exactly what an
 * operator reported: every list opened the edit page except the handful that did
 * not. An earlier draft of this docblock claimed the change rescued those
 * sixty-two from a view MODAL. It does not: `recordUrl` does win over
 * `recordAction` in the row markup (`@if ($recordUrl) … @elseif ($recordAction)`),
 * but that branch was never reached, and on those lists this seam is a NO-OP that
 * only states the rule they already followed.
 */
class RowClickTarget
{
    /**
     * Edit before view — the whole content of this class. Filament's is the reverse.
     */
    public const ORDER = ['edit', 'view'];

    /**
     * Answers Filament's two questions in one pass, in our order: the table's own
     * `edit`/`view` action if it resolves to a URL, else the resource's own page.
     *
     * Null means "nothing to link to", which leaves the row exactly as Filament
     * would have left it — the `recordAction` modal on a relation manager, or an
     * unclickable row where there is neither.
     *
     * A ROW IS NOT ALWAYS A MODEL. Filament types this `Model | array`, because a
     * table may be fed from `->records([...])` — **23 admin pages and 2 relation
     * managers are**, and 17 of those pages set no `recordUrl` of their own and so
     * arrive here. An array row addresses no record and belongs to no resource, so
     * it links to nothing; the ones that do want a link build their own
     * `recordUrl`, which runs after this and wins. Typing the parameter `Model`
     * instead is not a wrong answer, it is a **fatal** on mount — which is how
     * `AdminPageSmokeTest` caught it, having 500'd the eight such pages that test
     * happens to mount. (Counted, not estimated: the first version of this sentence
     * said "eight admin pages", which was the size of that test's failure list
     * rather than the size of the population.)
     */
    public static function for(Model|array $record, Table $table): ?string
    {
        if (! $record instanceof Model) {
            return null;
        }

        return static::fromTableActions($record, $table)
            ?? static::fromResourcePages($record, $table);
    }

    /**
     * The table's own `edit`/`view` action, when it carries a URL.
     *
     * An action resolving to NO url falls through rather than being mistaken for a
     * link — which is the ordinary case for a `ViewAction` on a resource with no
     * `view` page: `Page::getDefaultActionUrl()` finds no page to point at and
     * answers null. (Not because it "has a modal": `hasModal()` is null unless
     * somebody set `modal()` explicitly.) The action is cloned before it is bound
     * to a record, exactly as `ListRecords` does — the relation-manager copy
     * mutates the shared instance instead, and that is the copy worth not
     * imitating.
     */
    protected static function fromTableActions(Model $record, Table $table): ?string
    {
        foreach (static::ORDER as $name) {
            $action = $table->getAction($name);

            if (! $action instanceof Action) {
                continue;
            }

            $action = clone $action;
            $action->record($record);

            $group = $action->getGroup();

            while ($group) {
                $group->record($record);
                $group = $group->getGroup();
            }

            if ($action->isHidden()) {
                continue;
            }

            if (filled($url = $action->getUrl())) {
                return $url;
            }
        }

        return null;
    }

    /**
     * The resource's own `edit`/`view` page, for a list that declares no such action.
     *
     * Four admin lists declare neither (accounting periods, disbursements, owner
     * statement runs, stock movements) and only one of those registers a page at
     * all, so today this branch is reached by OwnerStatementRuns alone — which has
     * a `view` page and no `edit` one, and is therefore unchanged by any of this.
     *
     * The `canEdit()`/`canView()` clause is carried over from Filament's own
     * fallback rather than proven by a screen: on the lists that DO declare row
     * actions the refusal already happens one step earlier, because an `EditAction`
     * is `visible(fn ($record) => …canEdit($record))`. It is what stops the next
     * list that drops its row actions from linking a viewer at a form they will be
     * refused at, so it stays, stated as unexercised rather than implied to be
     * covered.
     *
     * Only a resource page can answer this — a relation manager, a widget or a
     * standalone table returns null and keeps whatever Filament gave it.
     *
     * TWO GUARDS THAT NOTHING REACHES TODAY, both because this seam now runs on
     * tables Filament's own closure never touched:
     *
     * - the ROW MUST BE THIS RESOURCE'S MODEL. `ManageRelatedRecords` is itself a
     *   `Resources\Pages\Page` listing somebody ELSE's model, so without this a
     *   charge row under `LeaseResource` would build `/leases/{chargeId}/edit` — a
     *   wrong record or a 404, waved through by a record-blind `canEdit()`. This is
     *   the question `ResourceAbility::may()` already refuses to answer for a
     *   relation manager, for the same reason. None exists here yet.
     *
     * - a TENANTED panel with no tenant selected. `Resource::getUrl()` fills the
     *   `{tenant}` segment from `Filament::getTenant()` and throws
     *   `UrlGenerationException` on null, which would take the whole render down
     *   rather than omit one link — the failure the three dashboard widgets each
     *   guard against in their own `recordUrl`.
     */
    protected static function fromResourcePages(Model $record, Table $table): ?string
    {
        $livewire = $table->getLivewire();

        if (! $livewire instanceof ResourcePage) {
            return null;
        }

        $resource = $livewire::getResource();

        if (! $record instanceof ($resource::getModel())) {
            return null;
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel?->hasTenancy() && ! Filament::getTenant()) {
            return null;
        }

        foreach (static::ORDER as $page) {
            if (! $resource::hasPage($page)) {
                continue;
            }

            if (! $resource::{'can'.ucfirst($page)}($record)) {
                continue;
            }

            return $livewire->getResourceUrl($page, ['record' => $record]);
        }

        return null;
    }
}
