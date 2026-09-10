<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Support\Filament\PropertyLink;
use App\Support\PropertyScope;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What this tenant has declared in sales, across every lease they hold (UX5-08).
 *
 * The LEASE twin of this tab answers "has this shop declared?"; the tenant 360 asks the
 * commercial question — is this retailer's turnover growing, and what percentage rent has it
 * produced — which spans their units and could not be seen anywhere without filtering the
 * register by hand. Same columns and the same read-only stance as the lease tab, deliberately:
 * two tables of one fact that disagree about how they show it is worse than one table.
 *
 * Originally:
 *
 * Only shown on a lease that OWES a declaration — on a lease with no reporting duty the table
 * would be permanently empty, which reads as "they have not declared" rather than "there is nothing
 * to declare".
 *
 * The duty, not the charge. `has_percentage_rent` answered both until 2026-08-30 and they are
 * different clauses: a mall collects turnover from tenants who owe no percentage rent, and this tab
 * is where those declarations live. `requiresSalesReporting()` follows the percentage-rent clause
 * unless the lease states otherwise, so nothing moved for a lease nobody has ruled on.
 *
 * "Have they declared this month?" is a lease question — the chase is per lease, the breakpoint is
 * on the lease, and the resulting overage bills to that lease. It was only answerable from the
 * declarations register, filtered by hand.
 *
 * Read-only. Declaring, estimating, locking and disputing all carry rules (the estimate marks
 * itself as one, a locked declaration is immutable, a void needs a stated reason) that live in the
 * declarations resource.
 */
class TenantSalesDeclarationsRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'salesDeclarations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.tenant_sales');
    }

    /**
     * Shown only when this tenant has a lease that OWES a declaration — asked of the LEASES, not of
     * the declarations, so a tenant who owes turnover and has not yet reported one still gets the
     * tab. Testing for existing rows instead would hide the tab exactly when the chase matters.
     *
     * Also gated on the reader's own right: a tenant record is opened by roles holding nothing in
     * this module, and a tab that 403s on click is worse than a tab that is not there.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // **ASKED OF THE LEASES IN SCOPE, NOT OF EVERY LEASE.** Now that the table narrows, an
        // unscoped question here puts the tab on screen for a tenant whose only percentage-rent
        // lease is in a mall this operator does not hold — badge, heading, and a table that can
        // never return a row. That is precisely the state this gate's own reasoning exists to
        // prevent: an empty table reads as "they have not declared", not as "there is nothing to
        // declare". Measured before this: tab visible, rows 0.
        return TenantSalesDeclarationResource::canViewAny()
            && PropertyScope::apply($ownerRecord->leases()->getQuery(), Lease::class, static::class)
                ->get()
                ->contains(fn ($lease) => $lease->requiresSalesReporting());
    }

    /**
     * **THE ONE PREDICATE, so the table and the BADGE cannot disagree.** See the note on
     * `TenantViolationsRelationManager::scoped()` — `CountsItsRows` counts the plain relationship,
     * which here would report as a NUMBER exactly the turnover rows the scope withholds.
     * Measured before this override: `rows=1 badge=2`.
     */
    protected static function scoped(Builder $query): Builder
    {
        return PropertyScope::apply($query, TenantSalesDeclaration::class, static::class);
    }

    /**
     * `getQuery()` because the relation is a `HasMany`, and what the scope narrows is the query
     * underneath it — the same query `modifyQueryUsing` is handed.
     */
    protected static function badgeCount(Model $ownerRecord): int
    {
        return static::scoped($ownerRecord->salesDeclarations()->getQuery())->count();
    }

    public function table(Table $table): Table
    {
        return $table
            // `lease.unit` is the chain `PropertyLink` walks to answer which mall each row belongs
            // to, and the Open action below asks that PER ROW — so without this the fix for a
            // cross-property 404 would ship two queries a row in its place.
            // **A TENANT'S DECLARED SALES ARE THIS MALL'S, NOT EVERY MALL'S.** A tenant is
            // `#[PortfolioShared]` — one retailer trades in several malls — so this relationship
            // spans the portfolio and an operator holding one mall was reading another's turnover figures
            // from it. Five of the nine sibling tabs on this page narrow by
            // property; these two were scoped by nothing at all, which is the shape a hand-picked isolation sweep leaves behind: the
            // 2026-07 sweep proved three of the nine tables under a shared owner and the rest were
            // never asked. Yardi scopes a customer's activity by the property you are in, and this
            // now reads the model's own `#[PropertyOwned]` rather than inventing a sixth spelling
            // of the rule — see App\Support\PropertyScope.
            ->modifyQueryUsing(fn (Builder $query) => static::scoped($query->with(['lease.unit'])))
            // No search box: a declaration is identified by its PERIOD, which is a date column, and
            // `TenantSalesDeclaration` carries no search blob. TableDefaults would otherwise render
            // a box that matches nothing — indistinguishable from "no such declaration", which is
            // the worst possible answer here. See App\Support\SearchPolicy.
            ->searchable(false)
            ->columns([
                TextColumn::make('period_start')
                    ->label(__('admin.tables.tenant_sales.period'))
                    ->date('M Y')
                    ->sortable(),

                TextColumn::make('declared_sales')
                    ->label(__('admin.tables.tenant_sales.declared_sales'))
                    ->money('EGP')
                    // An ESTIMATE is not a declaration: the sweep fills one in when a tenant misses
                    // the deadline so the rent can still be billed, and it must never be mistaken
                    // for a figure the tenant stood behind.
                    ->description(fn (TenantSalesDeclaration $record) => $record->is_estimate
                        ? __('admin.lease_sales_declarations.estimated')
                        : null),

                TextColumn::make('calculated_percentage_rent')
                    ->label(__('admin.tables.tenant_sales.percentage_rent'))
                    ->money('EGP')
                    ->weight('bold'),

                TextColumn::make('status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_sales.{$state}")),

                TextColumn::make('declared_at')
                    ->label(__('admin.tables.tenant_sales.declared_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            // NO header action, deliberately. A declaration is keyed on a LEASE — the breakpoint,
            // the exclusions and the resulting charge all live there — and a tenant may hold
            // several, so a create link here would have to guess which. The declarations REGISTER
            // has no tenant filter either, so a "see all" link would land on an unnarrowed list:
            // a control that appears to do something and does not is worse than no control. The
            // per-row Open action below is the one that actually goes somewhere.
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    // The property comes from the ROW, and now that this tab narrows to the mall
                    // in scope that is belt and braces — as it already is on the sibling tabs.
                    // It is written this way so the answer to "which mall is this row in" does not
                    // depend on a scoping decision made in another file, and so the link gate needs
                    // no exemption list.
                    ->url(fn (TenantSalesDeclaration $record): ?string => PropertyLink::to(TenantSalesDeclarationResource::class, $record))
                    // A ROW WITH NO PROPERTY GETS NO BUTTON, and one in a mall this operator cannot
                    // enter gets none either — `PropertyLink::to()` answers null for both, and an
                    // *Open* that goes nowhere is worse than no *Open*.
                    ->visible(fn (TenantSalesDeclaration $record): bool => TenantSalesDeclarationResource::canEdit($record)
                        && PropertyLink::to(TenantSalesDeclarationResource::class, $record) !== null),
            ])
            ->defaultSort('period_start', 'desc')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->emptyStateHeading(__('admin.lease_sales_declarations.empty_heading'))
            ->emptyStateDescription(__('admin.lease_sales_declarations.empty_description'));
    }
}
