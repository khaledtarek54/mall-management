<?php

namespace App\Filament\Owner\Resources\TenantRequests\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.maintenance.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),
                TextColumn::make('title')
                    ->label(__('admin.tables.maintenance.title'))
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.maintenance.tenant'))
                    ->searchable(),
                TextColumn::make('unit.asset.name')
                    ->label(__('admin.tables.asset.name'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
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
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_request.{$state}"))
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
                TextColumn::make('assignee.name')
                    ->label(__('admin.tables.maintenance.assigned_to'))
                    ->placeholder(__('admin.fields.unassigned'))
                    ->toggleable(),
                TextColumn::make('submitted_at')
                    ->label(__('admin.tables.maintenance.submitted'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.tenant_request')),
                SelectFilter::make('priority')
                    ->label(__('admin.filters.priority'))
                    ->options(fn () => __('admin.enums.maintenance_priority')),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
