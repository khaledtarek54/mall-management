<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Models\UtilityMeter;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The meters fitted to this unit.
 *
 * Part of the same gap as the unit's leases: the Unit resource surfaced none of its own
 * relationships, so "which meters serve this shop, and what do we recharge them at?" — asked every
 * time a tenant disputes a utility line — meant opening the meter register and filtering by hand.
 *
 * Read-only. A meter is registered in its own resource, where the reading history and the recharge
 * workflow live; duplicating creation here would give two places to get the rate wrong.
 */
class UnitMetersRelationManager extends RelationManager
{
    protected static string $relationship = 'utilityMeters';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.unit_meters.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('meter_number')
                    ->label(__('admin.fields.meter_number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.enums.meter_type')[$state] ?? $state),

                TextColumn::make('provider')
                    ->label(__('admin.fields.meter_provider'))
                    ->placeholder('—')
                    ->toggleable(),

                // The number a disputed utility line turns on.
                TextColumn::make('rate_per_unit')
                    ->label(__('admin.fields.rate_per_unit'))
                    ->money('EGP')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.meter.{$state}"))
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (UtilityMeter $record): string => UtilityMeterResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (UtilityMeter $record): bool => UtilityMeterResource::canEdit($record)),
            ])
            ->defaultSort('meter_number')
            ->emptyStateIcon('heroicon-o-bolt')
            ->emptyStateHeading(__('admin.unit_meters.empty_heading'))
            ->emptyStateDescription(__('admin.unit_meters.empty_description'));
    }
}
