<?php

namespace App\Filament\Admin\Resources\Assets\Tables;

use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Models\Asset;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.asset.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('code')
                    ->label(__('admin.tables.asset.code'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('admin.tables.asset.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.asset_type.{$state}"))
                    ->color('info'),
                TextColumn::make('city')
                    ->label(__('admin.tables.asset.city'))
                    ->searchable(),
                TextColumn::make('units_count')
                    ->label(__('admin.tables.asset.units'))
                    ->counts('units')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('leasable_area_sqm')
                    ->label(__('admin.fields.leasable_area_sqm'))
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' m²')
                    ->sortable()
                    // The load factor, underneath. `total_area_sqm` was collected by the form and
                    // read by nothing — this is what it was implicitly asking: how much of the
                    // building can actually be let. ~70% is normal for a mall; a figure far outside
                    // that usually means one of the two areas is wrong.
                    ->description(fn (Asset $record): ?string => $record->leasableEfficiencyPct() !== null
                        ? __('admin.tables.asset.of_gross', [
                            'gross' => number_format((float) $record->total_area_sqm, 0),
                            'pct' => number_format($record->leasableEfficiencyPct(), 1),
                        ])
                        : null),
                // Economic occupancy — the headline number for a mall, and it appeared on no property
                // screen at all. `Asset::areaOccupancyRate()` existed, was correct, and nothing
                // called it: the same "computed but unread" shape as the lease options whose
                // projected rent nobody read.
                TextColumn::make('occupancy')
                    ->label(__('admin.tables.asset.occupancy'))
                    ->badge()
                    // Null when there is no leasable area to measure — a property with no units is
                    // UNCONFIGURED, not empty, and a red 0% would say the opposite. The model keeps
                    // its 0.0 contract; the distinction lives here, where it is displayed.
                    ->state(fn (Asset $record): ?float => $record->totalUnitAreaSqm() > 0
                        ? $record->areaOccupancyRate()
                        : null)
                    ->formatStateUsing(fn (?float $state): string => $state === null
                        ? '—'
                        : number_format($state, 1).'%')
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 90 => 'success',
                        $state >= 75 => 'warning',
                        default => 'danger',
                    })
                    // What the percentage is made of, so the number is never a dead end.
                    ->description(fn (Asset $record): ?string => $record->totalUnitAreaSqm() > 0
                        ? __('admin.tables.asset.occupancy_detail', [
                            'let' => number_format($record->occupiedAreaSqm(), 0),
                            'total' => number_format($record->totalUnitAreaSqm(), 0),
                        ])
                        : null),
                IconColumn::make('is_active')
                    ->label(__('admin.tables.common.status'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.tables.asset.type'))
                    ->options(fn () => __('admin.enums.asset_type')),
                SelectFilter::make('city')
                    ->label(__('admin.filters.city'))
                    ->options(fn () => Asset::query()
                        ->whereNotNull('city')
                        ->distinct()
                        ->orderBy('city')
                        ->pluck('city', 'city')
                        ->all())
                    ->searchable(),
                TernaryFilter::make('is_active')
                    ->label(__('admin.filters.is_active'))
                    ->trueLabel(__('admin.filters.active_only'))
                    ->falseLabel(__('admin.filters.inactive_only')),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => AssetResource::canView($record))
                    ->authorize(fn ($record) => AssetResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => AssetResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => AssetResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => AssetResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => AssetResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->emptyStateHeading(__('admin.empty.assets.heading'))
            ->emptyStateDescription(__('admin.empty.assets.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.assets.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
