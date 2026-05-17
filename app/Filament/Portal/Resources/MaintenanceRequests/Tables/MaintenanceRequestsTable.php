<?php

namespace App\Filament\Portal\Resources\MaintenanceRequests\Tables;

use App\Models\MaintenanceRequest;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaintenanceRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('unit'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.maintenance.reference'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('title')
                    ->label(__('admin.tables.maintenance.title'))
                    ->searchable()
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.maintenance.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('category')
                    ->label(__('admin.tables.maintenance.category'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => __("admin.enums.maintenance_category.{$state}")),
                TextColumn::make('priority')
                    ->label(__('admin.tables.maintenance.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.maintenance_priority.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.maintenance_request.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'info',
                        'acknowledged' => 'warning',
                        'in_progress' => 'primary',
                        'awaiting_tenant' => 'warning',
                        'resolved' => 'success',
                        'closed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')
                    ->label(__('admin.tables.maintenance.submitted'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.maintenance_request')),
                Filter::make('open_only')
                    ->label(__('admin.filters.open_only'))
                    ->query(fn (Builder $query) => $query->whereIn('status', MaintenanceRequest::OPEN_STATUSES))
                    ->default(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('submitted_at', 'desc');
    }
}
