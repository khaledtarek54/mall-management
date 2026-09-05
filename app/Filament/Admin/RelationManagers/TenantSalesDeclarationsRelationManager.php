<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\TenantSalesDeclaration;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
        return TenantSalesDeclarationResource::canViewAny()
            && $ownerRecord->leases()->get()->contains(fn ($lease) => $lease->requiresSalesReporting());
    }

    public function table(Table $table): Table
    {
        return $table
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
                    ->url(fn (TenantSalesDeclaration $record): string => TenantSalesDeclarationResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (TenantSalesDeclaration $record): bool => TenantSalesDeclarationResource::canEdit($record)),
            ])
            ->defaultSort('period_start', 'desc')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->emptyStateHeading(__('admin.lease_sales_declarations.empty_heading'))
            ->emptyStateDescription(__('admin.lease_sales_declarations.empty_description'));
    }
}
