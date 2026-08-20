<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Tables;

use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Models\UtilityMeter;
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
            // `resolvedRatePerUnit()` walks the tariff's rungs; without this the badge is an N+1.
            ->modifyQueryUsing(fn ($query) => $query->with(['utilityTariff.rates']))
            ->columns([
                TextColumn::make('meter_number')
                    ->label(__('admin.tables.meter.number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->weight('medium')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.resources.asset.singular'))
                    ->badge()
                    ->sortable()
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
                // **What this meter charges per unit, and whether it can charge at all.**
                //
                // A meter with neither a tariff nor a `rate_per_unit` override prices every new
                // reading at 0.00, and `BillMeterReadingService` then refuses to bill it — correctly,
                // because "nobody set a price" must never be billed as "this supply is free". The
                // refusal arrived at BILLING time, on a reading already taken, and this list said
                // nothing. Measured on the seeded portfolio: 48 meters, none with either
                // (2026-08-20). The tariffs screen already flags a tariff with no rate; this is the
                // same signal one step earlier, where the meter is set up.
                TextColumn::make('effective_rate')
                    ->label(__('admin.utility_meters.rate'))
                    ->state(fn (UtilityMeter $record): string => ($rate = $record->resolvedRatePerUnit()) > 0
                        ? rtrim(rtrim(number_format($rate, 4), '0'), '.')
                            .($record->unit_of_measurement ? ' / '.$record->unit_of_measurement : '')
                        : __('admin.utility_meters.no_price'))
                    ->badge()
                    ->color(fn (UtilityMeter $record): string => $record->resolvedRatePerUnit() > 0 ? 'success' : 'danger')
                    // Which of the two answered — an override is a decision somebody made for this
                    // meter, and reads differently from the published price.
                    ->description(fn (UtilityMeter $record): ?string => $record->resolvedRatePerUnit() > 0
                        ? ($record->hasRateOverride()
                            ? __('admin.utility_meters.rate_override')
                            : $record->utilityTariff?->label())
                        : __('admin.utility_meters.no_price_hint')),
                TextColumn::make('provider')
                    ->label(__('admin.fields.meter_provider'))
                    ->sortable()
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
                    ->sortable()
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
