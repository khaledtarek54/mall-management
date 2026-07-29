<?php

namespace App\Support;

use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

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
 * Deliberately NOT set here: deferFilters (already true in Filament 4),
 * emptyState (per-resource copy), poll (a global poll would hammer MySQL).
 */
class TableDefaults
{
    public static function register(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table
                ->persistFiltersInSession()
                ->persistSearchInSession()
                ->persistColumnSearchesInSession()
                ->persistSortInSession()
                ->persistColumnsInSession()
                ->filtersLayout(FiltersLayout::Dropdown)
                ->filtersFormColumns(2)
                ->striped()
                ->defaultPaginationPageOption(25)
                ->paginationPageOptions([10, 25, 50, 100]);
        });
    }
}
