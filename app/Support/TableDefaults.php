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
 * - filtersLayout(AboveContentCollapsible) + filtersFormColumns(2) — the
 *   default is a dropdown panel that hides which filters are even available.
 *   Above-content-collapsible keeps them one click away and shows the active
 *   set. Two columns because most of our tables now carry 3-6 filters.
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
                ->filtersLayout(FiltersLayout::AboveContentCollapsible)
                ->filtersFormColumns(2)
                ->striped()
                ->defaultPaginationPageOption(25)
                ->paginationPageOptions([10, 25, 50, 100]);
        });
    }
}
