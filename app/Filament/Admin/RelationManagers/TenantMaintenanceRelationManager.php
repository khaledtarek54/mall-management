<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TenantMaintenanceRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceRequests';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.maintenance');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['unit', 'assignee'])->latest('submitted_at'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.maintenance.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('title')
                    ->label(__('admin.tables.maintenance.title'))
                    ->limit(40),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.maintenance.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('priority')
                    ->label(__('admin.tables.maintenance.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.maintenance_priority.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.maintenance_request.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted', 'in_progress' => 'info',
                        'acknowledged', 'awaiting_tenant' => 'warning',
                        'resolved' => 'success',
                        'closed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')
                    ->label(__('admin.tables.maintenance.submitted'))
                    ->date('d/m/Y'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.maintenance_request')),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.view'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MaintenanceRequest $record) => MaintenanceRequestResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([])
            ->defaultSort('submitted_at', 'desc');
    }
}
