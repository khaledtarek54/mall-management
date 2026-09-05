<?php

namespace App\Support;

use App\Models\Concerns\HasSearchText;
use App\Support\Filament\RowClickTarget;
use App\Support\Search\SearchText;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Panel-wide defaults for EVERY Filament table (admin + portal + relation
 * managers), registered once from AppServiceProvider::boot().
 *
 * Filament applies `configureUsing` callbacks inside `Table::make()`, i.e.
 * BEFORE the resource's own `XTable::configure($table)` runs — so anything a
 * resource sets explicitly still wins. This is the floor, not a ceiling.
 *
 * Why each default:
 *
 * - persist{Filters,Search,ColumnSearches,Sort,Columns}InSession — an operator
 *   working a list (chase the 90+ day bucket, work the open work-orders for
 *   Zone B) loses their filter the moment they open a record and come back.
 *   Filament stores these per-table in the session, so each list remembers its
 *   own state without polluting the URL.
 *
 * - filtersLayout(Dropdown) + filtersFormColumns(2) — filters open in a popover,
 *   two columns wide, next to the search box.
 *
 *   This was AboveContentCollapsible first, on the reasoning that an inline panel
 *   gives the wider tables (invoices carries 8 filters) more room than a popover.
 *   Looking at it in a browser, that was wrong on the arithmetic: the inline panel
 *   is COLLAPSED by default, so its extra room only appears once you click — while
 *   its trigger occupies a full-width row of its own on every table, on every page
 *   load, forever. Roughly 47px of permanent chrome bought a benefit that is only
 *   ever conditional. The popover holds the same two-column form and the same 8
 *   filters comfortably, so nothing was traded away for the row.
 *
 * - striped() — these are dense financial registers (invoice lines, GL rows,
 *   payroll); row banding is what makes a wide row scannable.
 *
 * - defaultPaginationPageOption(25) — 10 is too few for a monthly invoice run.
 *
 * - recordUrl(RowClickTarget) — clicking a row opens the record's EDIT page, and
 *   only falls back to View where this record cannot be edited. Filament's own
 *   default is the reverse, which made the click mean "read this" on the four
 *   resources that happen to register a `view` page and "work on this" on the
 *   other sixty-two. See App\Support\Filament\RowClickTarget for why the order
 *   is what it is and why it has to be set here rather than table by table.
 *
 * Deliberately NOT set here: deferFilters (already true in Filament 4),
 * emptyState (per-resource copy), poll (a global poll would hammer MySQL).
 */
class TableDefaults
{
    public static function register(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table
                ->searchable([self::blobSearch()])
                ->searchPlaceholder(fn (): string => __('admin.search.table_placeholder'))
                ->persistFiltersInSession()
                ->persistSearchInSession()
                ->persistColumnSearchesInSession()
                ->persistSortInSession()
                ->persistColumnsInSession()
                ->reorderableColumns()
                ->filtersLayout(FiltersLayout::Dropdown)
                ->filtersFormColumns(2)
                ->striped()
                ->recordUrl(fn (Model|array $record, Table $table): ?string => RowClickTarget::for($record, $table))
                ->defaultPaginationPageOption(25)
                ->paginationPageOptions([10, 25, 50, 100]);
        });
    }

    /**
     * The extra search constraint every table gets: the model's fold-normalized
     * `search_text` blob, ORed with whatever columns the table marks searchable.
     *
     * Registered here rather than table by table because the failure mode is
     * silent and the list is long. Before this, 5 tables rendered no search box
     * at all and 17 more searched exactly one column — `VendorsTable` searched
     * `name` while global search covered `legal_name`, `tax_id`, `email` and
     * `phone`, so the search bar could find a vendor by tax ID that the vendor
     * LIST could not. Doing it centrally means table #48 inherits correct search
     * instead of inheriting whichever column its author remembered to mark.
     *
     * The blob is the same one global search uses, so a list search folds Arabic
     * and ignores punctuation exactly like the top bar does — «شركه» finds
     * «شركة», `INV2026` finds `INV-2026`. Column-level `->searchable()` still
     * works and still matters: it is what powers per-column search boxes and what
     * reaches THROUGH a relation (`tenant.name`), which the blob deliberately
     * cannot (see `HasSearchText`).
     *
     * Guarded, not assumed: relation managers and any model without the trait
     * simply contribute nothing here, which leaves that table exactly as it was.
     */
    protected static function blobSearch(): \Closure
    {
        return function (Builder $query, string $search): Builder {
            $model = $query->getModel();

            if (! in_array(HasSearchText::class, class_uses_recursive($model), true)) {
                return $query;
            }

            // Qualified: a table search that joins (or is a relation manager's
            // query) would otherwise hit an ambiguous `search_text` the moment
            // two joined tables both carry the column.
            foreach (SearchText::words($search) as $word) {
                $query->where($query->qualifyColumn('search_text'), 'like', '%'.$word.'%');
            }

            return $query;
        };
    }
}
