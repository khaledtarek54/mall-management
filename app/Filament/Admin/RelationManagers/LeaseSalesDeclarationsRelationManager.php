<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What this tenant has declared in sales, and the percentage rent it produced.
 *
 * Only shown on a lease that actually has percentage rent — on a fixed-rent lease the table would
 * be permanently empty, which reads as "they have not declared" rather than "there is nothing to
 * declare". That distinction is the whole reason `has_percentage_rent` exists.
 *
 * "Have they declared this month?" is a lease question — the chase is per lease, the breakpoint is
 * on the lease, and the resulting overage bills to that lease. It was only answerable from the
 * declarations register, filtered by hand.
 *
 * Read-only. Declaring, estimating, locking and disputing all carry rules (the estimate marks
 * itself as one, a locked declaration is immutable, a void needs a stated reason) that live in the
 * declarations resource.
 */
class LeaseSalesDeclarationsRelationManager extends RelationManager
{
    protected static string $relationship = 'salesDeclarations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.tenant_sales');
    }

    /**
     * Hidden on a fixed-rent lease — see the class docblock. Filament asks this per record, so a
     * lease that later gains percentage rent gets the tab without any further wiring.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Lease && (bool) $ownerRecord->has_percentage_rent;
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
