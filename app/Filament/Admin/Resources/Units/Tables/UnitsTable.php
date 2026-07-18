<?php

namespace App\Filament\Admin\Resources\Units\Tables;

use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Exports\UnitExporter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'area', 'activeLease.tenant']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.tables.unit.code'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('floor')
                    ->label(__('admin.pdf.floor'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('category')
                    ->label(__('admin.tables.unit.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.category.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'food_beverage' => 'warning',
                        'retail' => 'info',
                        'wellness' => 'success',
                        'service' => 'gray',
                        'kiosk' => 'purple',
                        default => 'gray',
                    }),
                TextColumn::make('area_sqm')
                    ->label(__('admin.tables.unit.area'))
                    ->numeric(decimalPlaces: 0)
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('area.name')
                    ->label(__('admin.tables.unit.area_zone'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('activeLease.tenant.name')
                    ->label(__('admin.tables.unit.tenant'))
                    ->searchable()
                    ->placeholder('—')
                    ->weight('medium'),
                TextColumn::make('activeLease.base_rent_monthly')
                    ->label(__('admin.tables.unit.rent'))
                    ->money('EGP')
                    ->placeholder('—')
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('activeLease.expiry_date')
                    ->label(__('admin.widgets.top_tenants.lease_ends'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(function ($record) {
                        if (! $record->activeLease) {
                            return 'gray';
                        }
                        $days = (int) now()->diffInDays($record->activeLease->expiry_date, false);
                        if ($days < 30) {
                            return 'danger';
                        }
                        if ($days < 90) {
                            return 'warning';
                        }
                        return 'success';
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.unit.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'occupied' => 'success',
                        'vacant' => 'danger',
                        'reserved' => 'warning',
                        'maintenance' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.unit')),
                SelectFilter::make('category')
                    ->label(__('admin.tables.unit.category'))
                    ->options(fn () => collect(__('admin.enums.category'))->except(['office', 'storage'])->all()),
                SelectFilter::make('asset_id')
                    ->label(__('admin.filters.asset'))
                    ->relationship('asset', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('lease_expiring_soon')
                    ->label(__('admin.filters.expiring_soon'))
                    ->query(fn (Builder $query) => $query->whereHas('activeLease', fn (Builder $l) => $l->whereBetween('expiry_date', [now(), now()->addDays(90)]))),
                Filter::make('lease_expiring_critical')
                    ->label(__('admin.filters.expiring_critical'))
                    ->query(fn (Builder $query) => $query->whereHas('activeLease', fn (Builder $l) => $l->whereBetween('expiry_date', [now(), now()->addDays(30)]))),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(UnitExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray'),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record) => UnitResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(UnitExporter::class)
                        ->label(__('admin.actions.export')),
                    DeleteBulkAction::make()
                        ->visible(fn () => UnitResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => UnitResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => UnitResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('code')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading(__('admin.empty.units.heading'))
            ->emptyStateDescription(__('admin.empty.units.description'))
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label(__('admin.empty.units.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
