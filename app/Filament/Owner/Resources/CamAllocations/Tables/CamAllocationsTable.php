<?php

namespace App\Filament\Owner\Resources\CamAllocations\Tables;

use Filament\Actions\ViewAction;
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
                    ->label(__('admin.fields.asset')),
                TextColumn::make('lease.tenant.name')
                    ->label(__('admin.tables.tenant.name'))
                    ->searchable(),
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.tenant.units'))
                    ->badge(),
                TextColumn::make('pro_rata_share_pct')
                    ->label(__('admin.fields.tenant_share'))
                    ->suffix(' %')
                    ->numeric(2)
                    ->alignRight(),
                TextColumn::make('allocated_amount')
                    ->label(__('admin.fields.allocated_amount'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('true_up_amount')
                    ->label(__('admin.fields.true_up_amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state > 0 ? 'success' : ((float) $state < 0 ? 'danger' : 'gray')),
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
            ->defaultSort('cam_expense_pool_id', 'desc');
    }
}
