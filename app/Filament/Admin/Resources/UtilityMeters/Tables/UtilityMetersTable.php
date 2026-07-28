<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Tables;

use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UtilityMetersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('meter_number')
                    ->label(__('admin.tables.meter.number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->weight('medium')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.resources.asset.singular'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.meter.location'))
                    ->placeholder(__('admin.fields.common_area_placeholder'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('type')
                    ->label(__('admin.tables.meter.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.meter_type.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'electric' => 'warning',
                        'water' => 'info',
                        'gas' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('provider')
                    ->label(__('admin.fields.meter_provider'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.meter.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'faulty' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('readings_count')
                    ->label(__('admin.tables.meter.readings'))
                    ->counts('readings')
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.filters.meter_type'))
                    ->options(fn () => __('admin.enums.meter_type')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.meter')),
            ])
            ->defaultSort('meter_number')
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => UtilityMeterResource::canView($record))
                    ->authorize(fn ($record) => UtilityMeterResource::canView($record)),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-bolt')
            ->emptyStateHeading(__('admin.empty.utility_meters.heading'))
            ->emptyStateDescription(__('admin.empty.utility_meters.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.utility_meters.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
