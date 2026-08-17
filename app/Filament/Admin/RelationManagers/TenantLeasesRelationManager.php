<?php

namespace App\Filament\Admin\RelationManagers;

use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending_approval' => 'warning',
                        'renewed' => 'info',
                        'terminated', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.lease')),
                Filter::make('expiry_range')
                    ->label(__('admin.tables.lease.ends'))
                    ->schema([
                        DatePicker::make('expiry_from')
                            ->label(__('admin.filters.expiry_from'))
                            ->native(false),
                        DatePicker::make('expiry_until')
                            ->label(__('admin.filters.expiry_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['expiry_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('expiry_date', '>=', $date))
                        ->when($data['expiry_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('expiry_date', '<=', $date))),
            ])
            ->filtersFormColumns(2)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('commencement_date', 'desc')
            ->paginated(false);
    }
}
