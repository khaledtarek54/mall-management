<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Unit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AssetUnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.unit.plural');
    }

    /**
     * The recorded total, said out loud — the same idiom `AssetOwnersRelationManager` uses for the
     * ownership percentages, and for the same reason.
     *
     * `AreaFitsTheProperty` refuses only the impossible: ONE unit larger than the whole lettable
     * area. It deliberately does not refuse units that ADD UP to more than the property, because a
     * re-survey lands one unit at a time and a total-based refusal would lock the operator out of
     * correcting the very rows that put it over. So the total is shown where the areas are entered,
     * and flagged while it does not fit — which is the "warn" half of what the tester asked for.
     *
     * Silent when the property has not stated a leasable area: there is nothing to compare against,
     * and a warning about an unmeasured building is noise.
     */
    protected function recordedAreaNotice(): ?string
    {
        $asset = $this->getOwnerRecord();
        $leasable = (float) ($asset->leasable_area_sqm ?? 0);

        if ($leasable <= 0) {
            return null;
        }

        $recorded = (float) $asset->units()->sum('area_sqm');

        if ($recorded <= 0) {
            return null; // the empty state already says there are no units
        }

        return __(
            $recorded > round($leasable, 2)
                ? 'admin.sections.asset_units_area_over'
                : 'admin.sections.asset_units_area_total',
            [
                'recorded' => number_format($recorded, 2),
                'leasable' => number_format($leasable, 2),
            ],
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): ?string => $this->recordedAreaNotice())
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
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0).' m²')
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
                    ->url(fn (Unit $record) => UnitResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
