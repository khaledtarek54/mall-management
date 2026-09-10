<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Violation;
use App\Models\ViolationCategory;
use App\Support\Filament\PropertyLink;
use App\Support\PropertyScope;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * This tenant's compliance history — the tab the 360 view was missing (UX5-08).
 *
 * "Have they been a problem?" is one of the questions a tenant record exists to answer, and it was
 * answerable only from the violations register filtered by hand. It belongs beside the requests
 * and the ledger: a repeat offender is a commercial fact about a tenancy, not a facilities note.
 *
 * READ-ONLY, and the reasoning is the same as the sales tab's. Recording a violation carries rules
 * that live in the resource — the category names the standard fine, a fine becomes an invoice
 * through `BillViolationFineService`, and a billed violation freezes. A thinner form here would
 * own none of them, so the header action LINKS to the real one with the tenant carried across.
 */
class TenantViolationsRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'violations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return ViolationResource::getPluralModelLabel();
    }

    /**
     * Gated on the violations module AND the reader's own right to see one. A tenant record is
     * opened by roles that hold nothing in this module, and a tab that 403s on click is worse
     * than a tab that is not there.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ViolationResource::canViewAny();
    }

    /**
     * **THE ONE PREDICATE, so the table and the BADGE cannot disagree.**
     *
     * `CountsItsRows` counts the plain relationship, and its own docblock forbids using it
     * unmodified on a manager that narrows: *"a tab that shows two rows under a badge saying five
     * is worse than an unbadged tab"*. Here it would have been worse than unreconcilable — the
     * badge counts the very rows the table refuses to show, so it reported *"this retailer has 1
     * violation you are not allowed to read"* and handed back as a NUMBER exactly what the scope
     * withholds as rows. Measured before this override: `rows=1 badge=2`.
     */
    protected static function scoped(Builder $query): Builder
    {
        return PropertyScope::apply($query, Violation::class, static::class);
    }

    /**
     * `getQuery()` because the relation is a `HasMany`, and what the scope narrows is the query
     * underneath it — the same query `modifyQueryUsing` is handed.
     */
    protected static function badgeCount(Model $ownerRecord): int
    {
        return static::scoped($ownerRecord->violations()->getQuery())->count();
    }

    public function table(Table $table): Table
    {
        return $table
            // **A TENANT'S VIOLATIONS ARE THIS MALL'S, NOT EVERY MALL'S.** A tenant is
            // `#[PortfolioShared]` — one retailer trades in several malls — so this relationship
            // spans the portfolio and an operator holding one mall was reading another's compliance history
            // from it. Five of the nine sibling tabs on this page narrow by
            // property; these two were scoped by nothing at all, which is the shape a hand-picked isolation sweep leaves behind: the
            // 2026-07 sweep proved three of the nine tables under a shared owner and the rest were
            // never asked. Yardi scopes a customer's activity by the property you are in, and this
            // now reads the model's own `#[PropertyOwned]` rather than inventing a sixth spelling
            // of the rule — see App\Support\PropertyScope.
            ->modifyQueryUsing(fn (Builder $query) => static::scoped($query))
            // No search box: a violation is identified by its date and category, and `Violation`
            // carries no search blob — a box that matches nothing reads as "no such violation".
            ->searchable(false)
            ->columns([
                TextColumn::make('violation_date')
                    ->label(__('admin.violations.fields.violation_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('category')
                    ->label(__('admin.violations.fields.category'))
                    ->badge()
                    ->color('gray')
                    // Through the CATALOGUE, exactly as the register's own column does: an operator
                    // may add or retire a category, and a retired one must still label the rows
                    // that carry it (IsCodeCatalogue::labelFor reads inactive rows on purpose).
                    ->formatStateUsing(fn (?string $state) => ViolationCategory::labelFor($state)),

                TextColumn::make('fine_amount')
                    ->label(__('admin.violations.fields.fine_amount'))
                    ->money('EGP')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('admin.violations.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.violation.$state"))
                    ->color(fn (string $state) => match ($state) {
                        Violation::STATUS_RESOLVED => 'success',
                        default => 'warning',
                    }),
            ])
            // NO HEADER ACTION — see TenantPaymentsRelationManager. *Record violation* is
            // `TenantActions::recordViolation()` now, in the record's header on both pages.
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    // The property comes from the ROW, and now that this tab narrows to the mall
                    // in scope that is belt and braces — as it already is on the sibling tabs.
                    // It is written this way so the answer to "which mall is this row in" does not
                    // depend on a scoping decision made in another file, and so the link gate needs
                    // no exemption list.
                    ->url(fn (Violation $record): ?string => PropertyLink::to(ViolationResource::class, $record))
                    // A ROW WITH NO PROPERTY GETS NO BUTTON, and one in a mall this operator cannot
                    // enter gets none either — `PropertyLink::to()` answers null for both, and an
                    // *Open* that goes nowhere is worse than no *Open*.
                    ->visible(fn (Violation $record): bool => ViolationResource::canEdit($record)
                        && PropertyLink::to(ViolationResource::class, $record) !== null),
            ])
            // Newest first: this is a LEDGER of dated events, and the recent ones are the ones a
            // leasing decision turns on (App\Support\TableSortPolicy).
            ->defaultSort('violation_date', 'desc')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(__('admin.tenant_violations.empty_heading'))
            ->emptyStateDescription(__('admin.tenant_violations.empty_description'));
    }
}
