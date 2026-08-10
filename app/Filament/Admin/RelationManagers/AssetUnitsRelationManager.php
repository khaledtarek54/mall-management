<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Unit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AssetUnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.unit.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['activeLease.tenant']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.tables.unit.code'))
                    ->badge()
                    ->color('gray')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('floor.code')
                    ->label(__('admin.tables.unit.floor'))
                    ->toggleable(),
                TextColumn::make('category')
                    ->label(__('admin.tables.unit.category'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.enums.category.{$state}") : '—'),
                TextColumn::make('area_sqm')
                    ->label(__('admin.tables.unit.area'))
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0) . ' m²')
                    ->sortable(),
                TextColumn::make('activeLease.tenant.name')
                    ->label(__('admin.tables.unit.tenant'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('activeLease.base_rent_monthly')
                    ->label(__('admin.tables.unit.rent'))
                    ->money('EGP', divideBy: 1)
                    ->placeholder('—')
                    ->color('success'),
                TextColumn::make('activeLease.expiry_date')
                    ->label(__('admin.tables.unit.lease_expiry'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(fn ($state) => $state && $state->isBefore(now()->addDays(90)) ? 'warning' : null)
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.unit.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'occupied' => 'success',
                        'vacant' => 'warning',
                        'maintenance' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.unit')),
                SelectFilter::make('category')
                    ->label(__('admin.filters.category'))
                    ->options(fn () => __('admin.enums.category')),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make()
                    ->url(fn (Unit $record) => \App\Filament\Admin\Resources\Units\UnitResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
