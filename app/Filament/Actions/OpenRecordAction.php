<?php

namespace App\Filament\Actions;

use App\Support\Filament\PropertyLink;
use App\Support\Filament\RowClickTarget;
use App\Support\PropertyIsolation;
use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * "Open" — from a tab that LISTS a record to the screen that WORKS on it.
 *
 * **A factory rather than a row action copied per tab**, for the reason {@see LedgerEntryAction}
 * and {@see PostMonthAction} give and this one demonstrated: measured on 2026-09-11 the panel
 * carried TWELVE hand-written copies of this act (eleven `Action::make('open')` and
 * `AssetUnitsRelationManager`'s `EditAction` carrying a URL) in FOUR shapes, plus two cell links
 * built the same way (`AssetStaffRelationManager`'s email column, `BillingForecastRelationManager`'s
 * invoiced-period figure). `LeaseInvoicesRelationManager`'s had no visibility gate at all — a
 * viewer holding `invoices.view` and not `invoices.edit` got a link straight into a 403 — and
 * `TenantRequestsRelationManager`'s gated on the link resolving, never on the reader's right; six
 * gated on `canEdit` alone, so a view-only role lost the link on exactly the tabs where
 * {@see RowClickTarget} says the View page is the fallback; four resolved the row's OWN property
 * through `PropertyLink` and the rest let `getUrl()` read the switcher (or passed the tab's owner
 * by hand), which is the 404 that class exists to prevent; and one was labelled *View*. Each file
 * was right on its own terms and no two agreed. (An earlier draft of this paragraph named
 * `AssetRentableItemsRelationManager` as ungated and counted "four" and "three" — the review
 * re-read every copy against `git show HEAD:` and corrected all three figures.)
 *
 * ## What it resolves, and in what order
 *
 * The same answer as clicking the row on a register — `RowClickTarget::ORDER`, edit before view,
 * answered PER RECORD: an operator who may edit lands on the Edit page, a viewer on the View page
 * where the resource has one, and a record the reader may do neither with offers no button. The
 * URL names the record's own property (`PropertyLink`), never the switcher, so a tab that spans
 * malls — a tenant's violations, a portfolio-wide property's units — links into the right mall or
 * not at all. A shared master (no property of its own) links through the resource directly.
 *
 * `$through` is for a tab whose row is not the record it opens: the unit's encumbrances tab lists
 * lease OPTIONS and opens the LEASE behind each one.
 *
 * ## Why the row click follows it
 *
 * `RowClickTarget` reads a table's `open` action as the third name in its order, so on a tab
 * carrying this act clicking the row goes where the button goes — one resolution, not two. On a
 * register nothing changes: `edit`/`view` come first and are the ones a register declares.
 *
 * Usage: `OpenRecordAction::make(InvoiceResource::class)` in a relation manager's
 * `recordActions()`; `OpenRecordAction::make(LeaseResource::class, fn (LeaseOption $o) => $o->lease)`
 * where the row is not the record.
 */
final class OpenRecordAction
{
    public const NAME = 'open';

    /**
     * @param  class-string  $resource  the resource whose page the row opens
     * @param  Closure|null  $through  maps the ROW to the record to open; null opens the row itself
     */
    public static function make(string $resource, ?Closure $through = null): Action
    {
        $target = fn (Model $record): ?Model => $through === null ? $record : $through($record);

        return Action::make(self::NAME)
            ->label(__('admin.actions.open'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn (Model $record): ?string => self::urlFor($resource, $target($record)))
            // A button that goes nowhere is worse than none — `PropertyLink`'s rule, and the
            // row-click rule. Hidden exactly when the URL is null.
            ->visible(fn (Model $record): bool => self::urlFor($resource, $target($record)) !== null);
    }

    /**
     * The page this reader may open the record on, in the row-click order — or null.
     *
     * @param  class-string  $resource
     */
    public static function urlFor(string $resource, ?Model $record): ?string
    {
        if ($record === null) {
            return null;
        }

        try {
            foreach (RowClickTarget::ORDER as $page) {
                if ($page === self::NAME || ! $resource::hasPage($page)) {
                    continue;
                }

                if (! $resource::{'can'.ucfirst($page)}($record)) {
                    continue;
                }

                // A property-owned record links into ITS mall; `PropertyLink` answers null for a
                // mall the reader cannot enter or a row whose property cannot be resolved, and
                // that null is the answer — never a fallback to the switcher.
                //
                // Except a row that has NO property BY DESIGN: a `portfolioRowsWhenNull` model
                // (a deposit movement filed against no mall) is visible under every mall, so the
                // reader's own is the right one — the review caught this as the one case where
                // the copies this replaced linked and the factory went silent.
                if (PropertyIsolation::isOwned($record::class)
                    && ! (PropertyLink::assetOf($record) === null && PropertyIsolation::portfolioRowsWhenNull($record::class))) {
                    return PropertyLink::to($resource, $record, $page);
                }

                return $resource::getUrl($page, ['record' => $record]);
            }
        } catch (Throwable) {
            // A tenanted panel with no tenant selected throws from `getUrl()`; a missing route
            // throws too. Either is a reason to show no link, never to take the tab down.
            return null;
        }

        return null;
    }
}
