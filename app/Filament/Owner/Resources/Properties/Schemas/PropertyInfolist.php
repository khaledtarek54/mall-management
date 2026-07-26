<?php

namespace App\Filament\Owner\Resources\Properties\Schemas;

use App\Models\Asset;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PropertyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.sections.property_details'))
                ->columns(3)
                ->components([
                    TextEntry::make('name')->label(__('admin.tables.asset.name'))->weight('semibold'),
                    TextEntry::make('code')->label(__('admin.tables.asset.code'))->badge(),
                    TextEntry::make('type')->label(__('admin.tables.asset.type'))->badge(),
                    TextEntry::make('city')->label(__('admin.tables.asset.city')),
                    TextEntry::make('address')->label(__('admin.fields.address'))->columnSpan(2),
                    TextEntry::make('total_area_sqm')
                        ->label(__('admin.tables.asset.total_sqm'))
                        ->formatStateUsing(fn ($state) => number_format((float) $state, 0) . ' m²'),
                    TextEntry::make('leasable_area_sqm')
                        ->label(__('admin.tables.asset.leasable_sqm'))
                        ->formatStateUsing(fn ($state) => number_format((float) $state, 0) . ' m²'),
                    TextEntry::make('currency')->label(__('admin.fields.currency')),
                ]),
            Section::make(__('admin.sections.property_performance'))
                ->columns(3)
                ->components([
                    TextEntry::make('occupancyRate')
                        ->label(__('admin.tables.asset.occupancy'))
                        ->getStateUsing(fn (Asset $record) => $record->occupancyRate() . '%')
                        ->badge()
                        ->color(fn (Asset $record) => match (true) {
                            $record->occupancyRate() >= 70 => 'success',
                            $record->occupancyRate() >= 50 => 'warning',
                            default => 'danger',
                        }),
                    TextEntry::make('areaOccupancyRate')
                        ->label(__('admin.tables.asset.area_occupancy'))
                        ->getStateUsing(fn (Asset $record) => $record->areaOccupancyRate() . '%')
                        ->badge()
                        ->color(fn (Asset $record) => match (true) {
                            $record->areaOccupancyRate() >= 70 => 'success',
                            $record->areaOccupancyRate() >= 50 => 'warning',
                            default => 'danger',
                        }),
                    TextEntry::make('occupiedUnits')
                        ->label(__('admin.widgets.mall_stats.occupancy').' — '.__('admin.statuses.unit.occupied'))
                        ->getStateUsing(fn (Asset $record) => $record->occupiedUnitsCount()),
                    TextEntry::make('vacantUnits')
                        ->label(__('admin.statuses.unit.vacant'))
                        ->getStateUsing(fn (Asset $record) => $record->vacantUnitsCount()),
                ]),
        ]);
    }
}
