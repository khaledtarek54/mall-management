<?php

namespace App\Filament\Portal\Resources\CamAllocations\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CamAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pool.period_year')
                    ->label(__('admin.fields.period_year'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('pool.asset.name')
                    ->label(__('admin.fields.asset'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pro_rata_share_pct')
                    ->label(__('admin.fields.your_share'))
                    ->suffix(' %')
                    ->numeric(2)
                    ->alignRight(),
                TextColumn::make('allocated_amount')
                    ->label(__('admin.fields.allocated_amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('estimated_paid')
                    ->label(__('admin.fields.estimated_paid'))
                    ->money('EGP')
                    ->alignRight()
                    ->toggleable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('true_up_amount')
                    ->label(__('admin.fields.true_up_amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : ((float) $state < 0 ? 'success' : 'gray'))
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.cam_allocation.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'billed' => 'success',
                        'pending' => 'warning',
                        'disputed' => 'danger',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn () => __('admin.statuses.cam_allocation')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('cam_expense_pool_id', 'desc')
            ->emptyStateIcon('heroicon-o-receipt-percent')
            ->emptyStateHeading(__('admin.empty.portal_cam_allocations.heading'))
            ->emptyStateDescription(__('admin.empty.portal_cam_allocations.description'));
    }
}
