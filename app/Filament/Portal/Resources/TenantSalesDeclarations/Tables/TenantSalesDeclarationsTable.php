<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantSalesDeclarationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lease.unit']))
            ->columns([
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.tenant_sales.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.tenant_sales.period'))
                    ->formatStateUsing(fn ($state) => $state->isoFormat('MMM YYYY'))
                    ->sortable(),
                TextColumn::make('declared_sales')
                    ->label(__('admin.tables.tenant_sales.declared_sales'))
                    ->money('EGP', divideBy: 1)
                    ->placeholder(__('admin.tables.tenant_sales.pending_review'))
                    ->weight('semibold')
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('calculated_percentage_rent')
                    ->label(__('admin.tables.tenant_sales.percentage_rent'))
                    ->money('EGP', divideBy: 1)
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
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.tenant_sales')),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->emptyStateHeading(__('admin.empty.portal_tenant_sales.heading'))
            ->emptyStateDescription(__('admin.empty.portal_tenant_sales.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.portal_tenant_sales.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
