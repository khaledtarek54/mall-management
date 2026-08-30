<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Tables;

use App\Filament\Admin\Actions\SalesDeclarationActions;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantSalesDeclarationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lease.tenant', 'lease.unit', 'media']))
            ->columns([
                TextColumn::make('lease.tenant.name')
                    ->label(__('admin.tables.tenant_sales.tenant'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.tenant_sales.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.tenant_sales.period'))
                    ->formatStateUsing(fn ($state) => $state->isoFormat('MMM YYYY'))
                    ->sortable(),
                IconColumn::make('has_report')
                    ->label(__('admin.tables.tenant_sales.report'))
                    ->state(fn (TenantSalesDeclaration $record) => $record->hasReport())
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray'),
                TextColumn::make('declared_sales')
                    ->label(__('admin.tables.tenant_sales.declared_sales'))
                    ->money('EGP', divideBy: 1)
                    ->placeholder(__('admin.tables.tenant_sales.pending_review'))
                    ->sortable()
                    ->weight('semibold')
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('calculated_percentage_rent')
                    ->label(__('admin.tables.tenant_sales.percentage_rent'))
                    ->money('EGP', divideBy: 1)
                    // Mark annual (cumulative) leases: a bare figure on an annual lease is a running
                    // total's share and can't be understood without the "View working" breakdown.
                    ->description(fn (TenantSalesDeclaration $record) => SalesDeclarationActions::isAnnualLease($record)
                        ? __('admin.tables.tenant_sales.annual_cumulative')
                        : null)
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_sales.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'warning',
                        'locked' => 'success',
                        'disputed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('declared_at')
                    ->label(__('admin.tables.tenant_sales.declared_at'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('locked_at')
                    ->label(__('admin.tables.tenant_sales.locked_at'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.tenant_sales')),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => TenantSalesDeclarationResource::canView($record))
                    ->authorize(fn ($record) => TenantSalesDeclarationResource::canView($record)),
                ...SalesDeclarationActions::all(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-presentation-chart-line')
            ->emptyStateHeading(__('admin.empty.tenant_sales.heading'))
            ->emptyStateDescription(__('admin.empty.tenant_sales.description'));
    }
}
