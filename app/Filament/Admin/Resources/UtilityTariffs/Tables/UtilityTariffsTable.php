<?php

namespace App\Filament\Admin\Resources\UtilityTariffs\Tables;

use App\Filament\Admin\Resources\UtilityTariffs\UtilityTariffResource;
use App\Models\UtilityTariff;
use App\Support\ValueSets;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UtilityTariffsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager-loads the ladder because `rateOn()` reads the loaded collection when there is
            // one — without this the "current price" column is a query per row.
            ->modifyQueryUsing(fn ($query) => $query->with('rates')->withCount('meters'))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.code'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->state(fn (UtilityTariff $record) => $record->label())
                    ->description(fn (UtilityTariff $record) => $record->provider)
                    ->wrap(),

                TextColumn::make('utility_type')
                    ->label(__('admin.fields.meter_type'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __("admin.enums.meter_type.{$state}")),

                // The number the operator actually came to check: what this tariff charges TODAY.
                // A ladder of dated rungs is correct and unreadable at a glance; this is the answer.
                TextColumn::make('current_rate')
                    ->label(__('admin.utility_tariffs.current_rate'))
                    ->state(function (UtilityTariff $record): string {
                        $rate = $record->rateOn();

                        return $rate === null
                            ? '—'
                            : rtrim(rtrim(number_format($rate, 4), '0'), '.')
                                .($record->unit_of_measurement ? ' / '.$record->unit_of_measurement : '');
                    })
                    // A tariff nobody has priced resolves to null, and every meter on it costs 0 —
                    // which BillMeterReadingService refuses. Saying so here is cheaper than
                    // discovering it at billing time.
                    ->description(fn (UtilityTariff $record) => $record->rateOn() === null
                        ? __('admin.utility_tariffs.no_rate_yet')
                        : null)
                    ->badge()
                    ->color(fn (UtilityTariff $record) => $record->rateOn() === null ? 'danger' : 'success'),

                // Whether a rise has been entered ahead of time — the capability this whole screen
                // exists for, so it is worth being able to see at a glance that it is armed.
                TextColumn::make('scheduled')
                    ->label(__('admin.utility_tariffs.scheduled_change'))
                    ->state(function (UtilityTariff $record): ?string {
                        $next = $record->rates->first(fn ($r) => $r->effective_from->isFuture());

                        return $next === null
                            ? null
                            : rtrim(rtrim(number_format((float) $next->rate_per_unit, 4), '0'), '.')
                                .' · '.$next->effective_from->format('d/m/Y');
                    })
                    ->placeholder('—')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('meters_count')
                    ->label(__('admin.utility_tariffs.meters_priced'))
                    ->badge()
                    ->color('gray'),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('utility_type')
                    ->label(__('admin.fields.meter_type'))
                    ->options(fn () => collect(ValueSets::allowed('utility_tariffs', 'utility_type') ?? [])
                        ->mapWithKeys(fn (string $t) => [$t => __("admin.enums.meter_type.{$t}")])
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),
            ])
            ->recordActions([
                // A read-only view for the roles that hold `.view` without `.edit` — operations can
                // see what a meter is priced at without being able to move the price.
                ViewAction::make()
                    ->visible(fn (UtilityTariff $record) => UtilityTariffResource::canView($record)),
                EditAction::make(),
            ]);
    }
}
