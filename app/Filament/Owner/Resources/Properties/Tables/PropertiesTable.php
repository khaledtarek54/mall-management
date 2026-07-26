<?php

namespace App\Filament\Owner\Resources\Properties\Tables;

use App\Models\Asset;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PropertiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.asset.name'))
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('code')
                    ->label(__('admin.tables.asset.code'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('type')
                    ->label(__('admin.tables.asset.type'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('city')
                    ->label(__('admin.tables.asset.city')),
                TextColumn::make('units_count')
                    ->label(__('admin.tables.asset.units'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('occupancyRate')
                    ->label(__('admin.tables.asset.occupancy'))
                    ->getStateUsing(fn (Asset $record) => $record->occupancyRate() . '%')
                    ->badge()
                    ->color(fn (Asset $record) => match (true) {
                        $record->occupancyRate() >= 70 => 'success',
                        $record->occupancyRate() >= 50 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('areaOccupancyRate')
                    ->label(__('admin.tables.asset.area_occupancy'))
                    ->getStateUsing(fn (Asset $record) => $record->areaOccupancyRate() . '%')
                    ->badge()
                    ->color(fn (Asset $record) => match (true) {
                        $record->areaOccupancyRate() >= 70 => 'success',
                        $record->areaOccupancyRate() >= 50 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('leasable_area_sqm')
                    ->label(__('admin.tables.asset.leasable_sqm'))
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0) . ' m²')
                    ->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
