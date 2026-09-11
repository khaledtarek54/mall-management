<?php

namespace App\Filament\Admin\RelationManagers;

use App\Support\BadgeColors;
use App\Support\Filament\DateRangeFilter;
use App\Support\TenantScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TenantLeasesRelationManager extends RelationManager
{
    protected static string $relationship = 'leases';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.leases');
    }

    public function table(Table $table): Table
    {
        return $table
            // Property isolation: a tenant may lease in several malls — a restricted user
            // must see only leases in their visible properties (null = portfolio/super_admin).
            ->modifyQueryUsing(fn ($query) => $query
                ->with('unit')
                ->when(
                    TenantScope::visibleAssetIds(),
                    fn ($q, $ids) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $ids)),
                ))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.lease.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.lease.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('base_rent_monthly')
                    ->label(__('admin.tables.lease.rent'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('commencement_date')
                    ->label(__('admin.tables.lease.start'))
                    ->date('d/m/Y'),
                TextColumn::make('expiry_date')
                    ->label(__('admin.tables.lease.ends'))
                    ->date('d/m/Y'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.lease.{$state}"))
                    ->color(BadgeColors::of('leases.status')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.lease')),
                DateRangeFilter::make('expiry_date', __('admin.tables.lease.ends'), name: 'expiry_range'),
            ])
            ->filtersFormColumns(2)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('commencement_date', 'desc')
            ->paginated(false);
    }
}
