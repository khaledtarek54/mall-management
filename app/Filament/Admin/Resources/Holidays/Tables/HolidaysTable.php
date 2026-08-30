<?php

namespace App\Filament\Admin\Resources\Holidays\Tables;

use App\Models\Holiday;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class HolidaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label(__('admin.fields.date'))
                    ->date('d/m/Y')
                    ->sortable()
                    // The weekday is the point: an operator entering Eid needs to see at a glance
                    // that it already falls on a Friday and costs them nothing.
                    ->description(fn (Holiday $record): string => $record->date->translatedFormat('l')),

                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->state(fn (Holiday $record): string => $record->label())
                    ->weight('bold'),

                TextColumn::make('kind')
                    ->label(__('admin.fields.kind'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.facility.holiday.kinds.{$state}"))
                    ->color(fn (string $state): string => $state === Holiday::KIND_CLOSURE ? 'danger' : 'warning')
                    ->description(fn (Holiday $record): ?string => $record->isClosure()
                        ? null
                        : trim((string) $record->opens_at).' – '.trim((string) $record->closes_at)),

                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    // A blank cell here means "every mall", which is the ordinary case and must not
                    // read as missing data.
                    ->placeholder(__('admin.facility.holiday.all_properties'))
                    ->color('gray'),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('admin.fields.kind'))
                    ->options(fn (): array => __('admin.facility.holiday.kinds')),
            ])
            // No search box: `TableDefaults` gives every table the folded-blob search, and a
            // holiday has no blob — it is a date and two names, in a list short enough to read.
            // A box that can never match anything is worse than none.
            ->searchable(false)
            ->recordActions([
                // A read-only view, for the role that holds `.view` and not `.edit`. Its schema is the
                // resource's own form rendered disabled, so it cannot drift from the fields that exist.
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('date', 'desc')
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading(__('admin.empty.holidays.heading'))
            ->emptyStateDescription(__('admin.empty.holidays.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.holidays.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
