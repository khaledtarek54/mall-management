<?php

namespace App\Filament\Admin\Resources\Trades\Tables;

use App\Models\Trade;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TradesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Fourteen rows of configuration. `TableDefaults` would otherwise render the folded
            // blob search box, and `Trade` carries no blob — a box that always returns nothing is
            // indistinguishable from "no such record".
            ->searchable(false)
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.code'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->state(fn (Trade $record): string => $record->label())
                    ->weight('bold'),

                TextColumn::make('standard_hourly_rate')
                    ->label(__('admin.facility.fields.standard_hourly_rate'))
                    ->money('EGP')
                    // A blank rate is not zero — it means no labour cost will be computed for this
                    // trade, which the operator should be able to see at a glance.
                    ->placeholder(__('admin.facility.no_rate')),

                TextColumn::make('default_nte')
                    ->label(__('admin.facility.fields.nte'))
                    ->money('EGP')
                    ->placeholder(__('admin.facility.no_nte'))
                    ->toggleable(),

                TextColumn::make('vendors_count')
                    ->label(__('admin.facility.fields.vendors'))
                    ->counts('vendors')
                    ->badge()
                    // Zero eligible vendors on an active trade is the finding this column exists
                    // for: work of that kind has nobody to dispatch to.
                    ->color(fn (int $state): string => $state === 0 ? 'warning' : 'gray'),

                TextColumn::make('work_orders_count')
                    ->label(__('admin.facility.order.plural'))
                    ->counts('workOrders')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('admin.fields.active')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('sort_order')
            ->emptyStateIcon('heroicon-o-wrench-screwdriver')
            ->emptyStateHeading(__('admin.empty.trades.heading'))
            ->emptyStateDescription(__('admin.empty.trades.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.trades.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
