<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders\Tables;

use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Models\MaintenanceWorkOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaintenanceWorkOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'unit']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.preventive_maintenance.fields.reference'))
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('title')
                    ->label(__('admin.preventive_maintenance.fields.title'))
                    ->weight('bold')
                    ->description(fn (MaintenanceWorkOrder $record) => __("admin.preventive_maintenance.categories.{$record->category}"))
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.preventive_maintenance.fields.property'))
                    ->badge()->color('gray')->toggleable(),
                TextColumn::make('scheduled_for')
                    ->label(__('admin.preventive_maintenance.fields.scheduled_for'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($state, MaintenanceWorkOrder $record) => ! $record->isTerminal() && $state && $state->isPast() ? 'danger' : null),
                TextColumn::make('progress')
                    ->label(__('admin.preventive_maintenance.fields.progress'))
                    ->state(fn (MaintenanceWorkOrder $record) => ($record->done_items_count ?? 0).' / '.($record->items_count ?? 0))
                    ->badge()
                    ->color(fn (MaintenanceWorkOrder $record) => ($record->items_count ?? 0) > 0 && ($record->done_items_count ?? 0) >= $record->items_count ? 'success' : 'gray'),
                TextColumn::make('status')
                    ->label(__('admin.preventive_maintenance.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.preventive_maintenance.statuses.$state"))
                    ->color(fn (string $state) => match ($state) {
                        'done' => 'success',
                        'in_progress' => 'warning',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
            ])
            ->recordActions([
                Action::make('start')
                    ->label(__('admin.preventive_maintenance.actions.start'))
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->visible(fn (MaintenanceWorkOrder $record) => $record->status === 'open' && (auth()->user()?->can('preventive_maintenance.complete') ?? false))
                    ->authorize(fn () => auth()->user()?->can('preventive_maintenance.complete') ?? false)
                    ->action(function (MaintenanceWorkOrder $record): void {
                        abort_unless((auth()->user()?->can('preventive_maintenance.complete') ?? false) && $record->status === 'open', 403);
                        $record->update(['status' => 'in_progress']);
                    }),
                Action::make('complete')
                    ->label(__('admin.preventive_maintenance.actions.complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MaintenanceWorkOrder $record) => ! $record->isTerminal() && (auth()->user()?->can('preventive_maintenance.complete') ?? false))
                    ->authorize(fn () => auth()->user()?->can('preventive_maintenance.complete') ?? false)
                    ->action(function (MaintenanceWorkOrder $record): void {
                        abort_unless((auth()->user()?->can('preventive_maintenance.complete') ?? false) && ! $record->isTerminal(), 403);
                        $record->update(['status' => 'done', 'completed_at' => now(), 'completed_by_user_id' => auth()->id()]);
                        Notification::make()->title(__('admin.preventive_maintenance.completed_notice'))->success()->send();
                    }),
                Action::make('cancel')
                    ->label(__('admin.preventive_maintenance.actions.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (MaintenanceWorkOrder $record) => ! $record->isTerminal() && MaintenanceWorkOrderResource::canEdit($record))
                    ->authorize(fn (MaintenanceWorkOrder $record) => MaintenanceWorkOrderResource::canEdit($record))
                    ->action(function (MaintenanceWorkOrder $record): void {
                        abort_unless(MaintenanceWorkOrderResource::canEdit($record) && ! $record->isTerminal(), 403);
                        $record->update(['status' => 'cancelled']);
                        Notification::make()->title(__('admin.preventive_maintenance.cancelled_notice'))->success()->send();
                    }),
                EditAction::make()->visible(fn (MaintenanceWorkOrder $record) => ! $record->isTerminal() && MaintenanceWorkOrderResource::canEdit($record)),
            ])
            ->defaultSort('scheduled_for', 'desc');
    }
}
